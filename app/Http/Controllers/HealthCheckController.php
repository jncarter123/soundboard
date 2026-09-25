<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Public, unauthenticated health endpoint. Responses only carry a status per
 * check; failure details go to the log so infrastructure info isn't leaked.
 */
class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('SELECT 1')),
            'cache' => $this->check('cache', fn () => $this->checkCache()),
            'redis' => $this->redisEnabled()
                ? $this->check('redis', fn () => Redis::connection()->ping())
                : 'skipped',
            'reverb' => $this->check('reverb', fn () => $this->checkReverb()),
        ];

        $healthy = collect($checks)->every(fn ($status) => $status !== 'error');

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * Run a check, returning 'ok' or 'error'. A check fails by throwing.
     */
    private function check(string $name, callable $callback): string
    {
        try {
            $callback();

            return 'ok';
        } catch (\Throwable $e) {
            Log::warning("Health check failed: {$name}", [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    /**
     * Redis is only a dependency when Reverb horizontal scaling is enabled.
     */
    private function redisEnabled(): bool
    {
        return (bool) config('reverb.servers.reverb.scaling.enabled');
    }

    private function checkCache(): void
    {
        $key = 'health_check_'.uniqid();
        Cache::put($key, 'ok', 10);
        $value = Cache::get($key);
        Cache::forget($key);

        if ($value !== 'ok') {
            throw new \RuntimeException('Cache read/write failed');
        }
    }

    private function checkReverb(): void
    {
        // Same target the metrics poller uses, so both agree on reachability.
        $scheme = config('reverb.metrics.scheme', 'http');
        $host = config('reverb.metrics.host', '127.0.0.1');
        $port = (int) config('reverb.metrics.port', 8080);

        Http::timeout(2)->get("{$scheme}://{$host}:{$port}/up")->throw();
    }
}
