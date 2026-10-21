<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Auth endpoints: keyed by email as well as IP so one address can't be hammered from many IPs.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
        ]);

        // Booking writes are limited per signed-in user.
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(20)->by('user:'.($request->user()?->id ?? $request->ip())));

        // Public read endpoints.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
