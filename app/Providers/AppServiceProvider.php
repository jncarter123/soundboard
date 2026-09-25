<?php

namespace App\Providers;

use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\ApplicationManager;
use Spatie\Activitylog\Models\Activity;

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
        $this->configureAuditing();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        $this->app->afterResolving(ApplicationManager::class, function (ApplicationManager $manager) {
            $manager->extend('database', fn () => new DatabaseApplicationProvider(
                ttl: (int) config('reverb.apps.cache_ttl', 10),
            ));
        });
    }

    /**
     * Request context on every audit entry, and sign-in events, which don't
     * come from any model.
     */
    protected function configureAuditing(): void
    {
        Activity::creating(fn (Activity $activity) => Audit::addContext($activity));

        Event::listen(CommandStarting::class, fn () => Audit::$runningCommand = true);
        Event::listen(CommandFinished::class, fn () => Audit::$runningCommand = false);

        Event::listen(Login::class, fn (Login $event) => Audit::log(
            'auth.login',
            $event->remember ? 'Signed in (remembered)' : 'Signed in',
            $event->user,
        ));

        Event::listen(Logout::class, fn (Logout $event) => $event->user && Audit::log('auth.logout', 'Signed out', $event->user));

        // No causer: nobody is signed in. The attempted email is kept so
        // guessing against one account shows up; passwords never are.
        Event::listen(Failed::class, fn (Failed $event) => Audit::log(
            'auth.failed',
            'Failed sign-in',
            $event->user,
            ['email' => $event->credentials['email'] ?? null],
        ));
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
