<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/client', fn () => [
            'ip' => request()->ip(),
            'url' => url('/login'),
        ]);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        URL::forceScheme(null);

        parent::tearDown();
    }

    protected function bootWith(array $config): void
    {
        config($config);
        (new AppServiceProvider($this->app))->boot();
    }

    protected function requestViaProxy(): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.9',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'soundboard.example.com',
            ])
            ->getJson('/_test/client')
            ->json();
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        $this->bootWith(['app.trusted_proxies' => null, 'app.url' => 'http://localhost']);

        $client = $this->requestViaProxy();

        // A client can't spoof its IP past the login and API rate limits.
        $this->assertSame('172.18.0.5', $client['ip']);
        $this->assertStringStartsWith('http://localhost', $client['url']);
    }

    public function test_trusting_all_proxies_honours_forwarded_headers(): void
    {
        $this->bootWith(['app.trusted_proxies' => '*', 'app.url' => 'http://localhost']);

        $client = $this->requestViaProxy();

        $this->assertSame('203.0.113.9', $client['ip']);
        $this->assertSame('https://soundboard.example.com/login', $client['url']);
    }

    public function test_only_listed_proxies_are_trusted(): void
    {
        $this->bootWith(['app.trusted_proxies' => '10.0.0.1, 10.0.0.2', 'app.url' => 'http://localhost']);

        $this->assertSame('172.18.0.5', $this->requestViaProxy()['ip']);
    }

    public function test_https_app_url_forces_https_links(): void
    {
        $this->bootWith(['app.trusted_proxies' => null, 'app.url' => 'https://soundboard.example.com']);

        $this->assertStringStartsWith('https://', url('/login'));
    }
}
