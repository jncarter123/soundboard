<?php

namespace App\Services;

use App\Models\ReverbApp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Pusher\Pusher;
use Throwable;

/**
 * Reads live stats from Reverb's Pusher-compatible HTTP API.
 *
 * Apps are polled concurrently, so a slow or unreachable Reverb server costs
 * one timeout in total rather than one per app. Callers run inside the
 * `pulse:check` loop and the dashboard, which must not stall.
 */
class ReverbApiService
{
    private const CONNECT_TIMEOUT = 1;

    private const TIMEOUT = 3;

    private const CONCURRENCY = 20;

    /**
     * With scaling, presence counts are recomputed from member lists, one
     * request per channel. Beyond this many channels the rest keep Reverb's
     * (over-counting) figure and are marked approximate.
     */
    private const MAX_PRESENCE_RECOUNTS = 100;

    public function getConnectionCount(ReverbApp $app): ?int
    {
        return $this->getConnectionCounts(collect([$app]))[$app->app_id];
    }

    /**
     * @param  Collection<int, ReverbApp>  $apps
     * @return array<string, int|null> Keyed by app_id; null when the poll failed.
     */
    public function getConnectionCounts(Collection $apps): array
    {
        return $this->getMany($apps, ['connections' => '/connections'])
            ->map(fn (array $bodies) => $this->connectionsFrom($bodies['connections']))
            ->all();
    }

    /**
     * Connection counts and channels for every app.
     *
     * @return list<array{app_id: string, name: string, connections: int|null, channels: array<string, array>|null}>
     */
    public function getAllAppsLiveStats(): array
    {
        $apps = ReverbApp::all();

        // Both endpoints for every app go out in one concurrent batch.
        $results = $this->getMany($apps, [
            'connections' => '/connections',
            // Reverb returns subscription_count only for non-presence channels
            // and user_count (distinct members) only for presence channels.
            'channels' => '/channels?info=subscription_count,user_count',
        ]);

        $stats = $apps->map(fn (ReverbApp $app) => [
            'app_id' => $app->app_id,
            'name' => $app->name,
            'connections' => $this->connectionsFrom($results[$app->app_id]['connections']),
            'channels' => ($body = $results[$app->app_id]['channels']) === null
                ? null
                : (array) ($body['channels'] ?? []),
        ])->all();

        return $this->scalingEnabled() ? $this->recountPresenceMembers($apps, $stats) : $stats;
    }

    /**
     * How many Reverb servers share this Redis: 1 without scaling. Each
     * server subscribes to the scaling channel, so it's that channel's
     * subscriber count. Null when Redis can't be asked.
     */
    public function getServerCount(): ?int
    {
        if (! $this->scalingEnabled()) {
            return 1;
        }

        try {
            // rawCommand, so Laravel's key prefix isn't applied to the channel name.
            $reply = Redis::connection()->client()->rawCommand(
                'PUBSUB', 'NUMSUB', (string) config('reverb.servers.reverb.scaling.channel', 'reverb'),
            );

            return max(1, (int) ($reply[1] ?? 0));
        } catch (Throwable $e) {
            Log::error('Unable to count Reverb servers in Redis', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public function scalingEnabled(): bool
    {
        return (bool) config('reverb.servers.reverb.scaling.enabled');
    }

    /**
     * With scaling, Reverb sums each server's distinct-member count, so a
     * user connected to two servers is counted twice. Its member list is
     * deduplicated across servers, so count that instead.
     *
     * @param  Collection<int, ReverbApp>  $apps
     */
    private function recountPresenceMembers(Collection $apps, array $stats): array
    {
        $budget = self::MAX_PRESENCE_RECOUNTS;

        foreach ($stats as $i => $stat) {
            $presence = array_values(array_filter(
                array_keys($stat['channels'] ?? []),
                fn ($channel) => str_starts_with($channel, 'presence-'),
            ));

            if ($presence === []) {
                continue;
            }

            $recount = array_slice($presence, 0, max(0, $budget));
            $budget -= count($recount);
            $app = $apps->firstWhere('app_id', $stat['app_id']);
            $members = $recount === [] ? [] : $this->getMany(
                collect([$app]),
                collect($recount)->mapWithKeys(fn ($channel) => [$channel => "/channels/{$channel}/users"])->all(),
            )[$app->app_id];

            foreach ($presence as $channel) {
                $body = $members[$channel] ?? null;

                if ($body !== null) {
                    $stats[$i]['channels'][$channel] = ['user_count' => count($body['users'] ?? [])];
                } else {
                    $stats[$i]['channels'][$channel] = [...(array) $stats[$i]['channels'][$channel], 'approximate' => true];
                }
            }
        }

        return $stats;
    }

    /**
     * The distinct user IDs in a presence channel. Reverb exposes only the
     * IDs, not the user_info clients send when joining.
     *
     * @return list<string>|null Null when the request failed.
     */
    public function getChannelMembers(ReverbApp $app, string $channel): ?array
    {
        $body = $this->getMany(collect([$app]), ['users' => "/channels/{$channel}/users"])[$app->app_id]['users'];

        return $body === null
            ? null
            : array_values(array_map(fn ($user) => (string) ($user['id'] ?? ''), $body['users'] ?? []));
    }

    /**
     * How far Reverb's clock is from ours, in seconds (positive: Reverb is
     * ahead), read from the Date header on its unsigned /up endpoint. Reverb
     * 1.12+ rejects signed API requests more than ten minutes off its own
     * clock, so this still works when every other call is being refused.
     *
     * Compared with the midpoint of the request to cancel network delay;
     * accurate to about a second, the resolution of the Date header.
     *
     * @return int|null Null when Reverb can't be reached or sends no Date.
     */
    public function getClockSkew(): ?int
    {
        try {
            $sentAt = microtime(true);
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->get($this->baseUrl().'/up');
            $midpoint = ($sentAt + microtime(true)) / 2;
        } catch (Throwable) {
            return null;
        }

        $date = $response->header('Date');
        $reverbTime = $date !== '' ? strtotime($date) : false;

        return $reverbTime === false ? null : (int) round($reverbTime - $midpoint);
    }

    private function connectionsFrom(?array $body): ?int
    {
        return isset($body['connections']) ? (int) $body['connections'] : null;
    }

    /**
     * Issue signed GETs for every app and endpoint in one concurrent batch.
     *
     * @param  Collection<int, ReverbApp>  $apps
     * @param  array<string, string>  $endpoints  name => path relative to /apps/{app_id}, optionally with a query string
     * @return Collection<string, array<string, array|null>> app_id => endpoint name => decoded JSON, or null on any failure.
     */
    private function getMany(Collection $apps, array $endpoints): Collection
    {
        $apps = $apps->keyBy('app_id');

        $responses = Http::pool(function (Pool $pool) use ($apps, $endpoints) {
            foreach ($apps as $app) {
                foreach ($endpoints as $name => $path) {
                    $this->signedGet($pool->as("{$app->app_id}:{$name}"), $app, $path);
                }
            }
        }, concurrency: self::CONCURRENCY);

        return $apps->map(fn (ReverbApp $app) => collect($endpoints)->map(
            fn (string $path, string $name) => $this->decode($responses["{$app->app_id}:{$name}"] ?? null, $app, $path)
        )->all());
    }

    private function decode(mixed $response, ReverbApp $app, string $path): ?array
    {
        if ($response instanceof Response && $response->successful() && is_array($response->json())) {
            return $response->json();
        }

        Log::error('Unable to query Reverb API', [
            'app_id' => $app->app_id,
            'path' => $path,
            'status' => $response instanceof Response ? $response->status() : null,
            'exception' => $response instanceof Throwable ? $response::class : null,
            'message' => $response instanceof Throwable ? $response->getMessage() : null,
        ]);

        return null;
    }

    /**
     * Queue a GET signed the same way the Pusher SDK signs it, which is what
     * Reverb's HTTP API verifies.
     */
    private function signedGet(PendingRequest $request, ReverbApp $app, string $path): void
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);

        $fullPath = "/apps/{$app->app_id}{$path}";

        $signed = Pusher::build_auth_query_params($app->key, $app->secret, 'GET', $fullPath, $params);

        $request
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->get($this->baseUrl().$fullPath, $signed);
    }

    private function baseUrl(): string
    {
        $scheme = config('reverb.metrics.scheme', 'http');
        $host = config('reverb.metrics.host', '127.0.0.1');
        $port = (int) config('reverb.metrics.port', 8080);

        return "{$scheme}://{$host}:{$port}";
    }
}
