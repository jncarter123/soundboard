<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        $this->app->afterResolving(ApplicationManager::class, function (ApplicationManager $manager) {
            $manager->extend('database', fn () => new DatabaseApplicationProvider(
                ttl: (int) config('reverb.apps.cache_ttl', 10),
            ));
        });
    }
}
