<?php

namespace Tests\Feature;

use App\Models\ReverbApp;
use App\Services\ReverbApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_no_apps_makes_no_requests(): void
    {
        Http::fake();

        $this->assertSame([], app(ReverbApiService::class)->getConnectionCounts(collect()));
        Http::assertNothingSent();
    }
}
