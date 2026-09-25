<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\ApplicationManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureProxies();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        $this->app->afterResolving(ApplicationManager::class, function (ApplicationManager $manager) {
            $manager->extend('database', fn () => new DatabaseApplicationProvider(
                ttl: (int) config('reverb.apps.cache_ttl', 10),
            ));
        });
    }

    /**
     * Behind a TLS-terminating proxy the app only sees plain http, so it has
     * to be told what the browser sees, or it builds http asset URLs that the
     * browser blocks as mixed content.
     *
     * TRUSTED_PROXIES covers the ordinary case, where the proxy sets
     * X-Forwarded-Proto. An https APP_URL also forces the scheme outright,
     * for when something in front of the proxy terminates TLS and the proxy
     * truthfully forwards "http".
     */
    protected function configureProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
            TrustProxies::withHeaders(
                Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
            );
        }

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
