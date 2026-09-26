<?php

namespace Tests\Feature;

use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class ReverbApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'reverb.metrics.scheme' => 'http',
            'reverb.metrics.host' => '127.0.0.1',
            'reverb.metrics.port' => 8080,
        ]);
    }

    protected function makeApp(string $appId): ReverbApp
    {
        return ReverbApp::create([
            'name' => $appId,
            'app_id' => $appId,
            'key' => 'key-'.$appId,
            'secret' => 'secret-'.$appId,
            'allowed_origins' => ['*'],
        ]);
    }

    /**
     * Verify a request's Pusher signature independently of the SDK helper,
     * per the Pusher HTTP API spec that Reverb enforces.
     */
    protected function assertValidSignature(Request $request, string $secret): void
    {
        $url = parse_url($request->url());
        parse_str($url['query'], $params);

        $signature = $params['auth_signature'];
        unset($params['auth_signature']);
        ksort($params);

        $query = collect($params)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        $expected = hash_hmac('sha256', "GET\n{$url['path']}\n{$query}", $secret);

        $this->assertSame($expected, $signature);
    }

    public function test_polls_every_app_with_signed_requests(): void
    {
        $this->makeApp('a');
        $this->makeApp('b');

        Http::fake([
            '127.0.0.1:8080/apps/a/connections*' => Http::response(['connections' => 3]),
            '127.0.0.1:8080/apps/b/connections*' => Http::response(['connections' => 7]),
        ]);

        $counts = app(ReverbApiService::class)->getConnectionCounts(ReverbApp::all());

        $this->assertSame(['a' => 3, 'b' => 7], $counts);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            $appId = str_contains($request->url(), '/apps/a/') ? 'a' : 'b';
            $this->assertValidSignature($request, 'secret-'.$appId);

            return true;
        });
    }

    public function test_one_failing_app_does_not_affect_others(): void
    {
        $this->makeApp('up');
        $this->makeApp('broken');
        $this->makeApp('down');

        Http::fake([
            '*/apps/up/*' => Http::response(['connections' => 5]),
            '*/apps/broken/*' => Http::response('Unauthorized', 401),
            '*/apps/down/*' => fn () => throw new ConnectionException('Connection refused'),
        ]);

        $counts = app(ReverbApiService::class)->getConnectionCounts(ReverbApp::all());

        $this->assertSame(['up' => 5, 'broken' => null, 'down' => null], $counts);
    }

    public function test_live_stats_include_channels_as_arrays(): void
    {
        $this->makeApp('a');

        Http::fake([
            '*/apps/a/connections*' => Http::response(['connections' => 2]),
            '*/apps/a/channels*' => Http::response(['channels' => [
                'private-orders' => ['subscription_count' => 2],
            ]]),
        ]);

        $stats = app(ReverbApiService::class)->getAllAppsLiveStats();

        $this->assertSame([[
            'app_id' => 'a',
            'name' => 'a',
            'connections' => 2,
            'channels' => ['private-orders' => ['subscription_count' => 2]],
        ]], $stats);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/channels')) {
                return false;
            }

            $this->assertStringContainsString('info=subscription_count', $request->url());
            $this->assertValidSignature($request, 'secret-a');

            return true;
        });
    }

    public function test_clock_skew_is_read_from_reverbs_date_header(): void
    {
        Http::fake(['127.0.0.1:8080/up' => Http::response('', 200, ['Date' => now()->addMinutes(12)->toRfc7231String()])]);

        $skew = app(ReverbApiService::class)->getClockSkew();

        $this->assertEqualsWithDelta(720, $skew, 2);
    }

    public function test_clock_skew_is_null_when_reverb_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertNull(app(ReverbApiService::class)->getClockSkew());
    }

    public function test_server_count_is_one_without_scaling(): void
    {
        config(['reverb.servers.reverb.scaling.enabled' => false]);

        $this->assertSame(1, app(ReverbApiService::class)->getServerCount());
    }

    public function test_server_count_is_the_scaling_channels_subscribers(): void
    {
        config(['reverb.servers.reverb.scaling.enabled' => true, 'reverb.servers.reverb.scaling.channel' => 'reverb']);
        $client = Mockery::mock();
        $client->shouldReceive('rawCommand')->with('PUBSUB', 'NUMSUB', 'reverb')->andReturn(['reverb', 3]);
        $connection = Mockery::mock();
        $connection->shouldReceive('client')->andReturn($client);
        Redis::shouldReceive('connection')->andReturn($connection);

        $this->assertSame(3, app(ReverbApiService::class)->getServerCount());
    }

    public function test_server_count_is_null_when_redis_fails(): void
    {
        config(['reverb.servers.reverb.scaling.enabled' => true]);
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('Connection refused'));

        $this->assertNull(app(ReverbApiService::class)->getServerCount());
    }

    public function test_with_scaling_presence_counts_come_from_the_deduplicated_member_list(): void
    {
        config(['reverb.servers.reverb.scaling.enabled' => true]);
        $this->makeApp('a');

        // What a 3-server cluster really returned: user 1 on two servers,
        // so Reverb's summed count says 11 while there are 7 members.
        Http::fake([
            '*/apps/a/connections*' => Http::response(['connections' => 12]),
            '*/apps/a/channels/presence-lobby/users*' => Http::response(['users' => array_map(fn ($id) => ['id' => $id], [1, 2, 3, 4, 5, 6, 7])]),
            '*/apps/a/channels?*' => Http::response(['channels' => [
                'presence-lobby' => ['user_count' => 11],
                'orders' => ['subscription_count' => 12],
            ]]),
        ]);

        $channels = app(ReverbApiService::class)->getAllAppsLiveStats()[0]['channels'];

        $this->assertSame(['user_count' => 7], $channels['presence-lobby']);
        $this->assertSame(['subscription_count' => 12], $channels['orders']);
    }

    public function test_without_scaling_presence_counts_are_used_as_is(): void
    {
        config(['reverb.servers.reverb.scaling.enabled' => false]);
        $this->makeApp('a');
        Http::fake([
            '*/apps/a/connections*' => Http::response(['connections' => 3]),
            '*/apps/a/channels?*' => Http::response(['channels' => ['presence-lobby' => ['user_count' => 3]]]),
        ]);

        $this->assertSame(['user_count' => 3], app(ReverbApiService::class)->getAllAppsLiveStats()[0]['channels']['presence-lobby']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/users'));
    }

    public function test_no_apps_makes_no_requests(): void
    {
        Http::fake();

        $this->assertSame([], app(ReverbApiService::class)->getConnectionCounts(collect()));
        Http::assertNothingSent();
    }
}
