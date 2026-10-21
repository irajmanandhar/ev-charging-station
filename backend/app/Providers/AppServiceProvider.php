<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\EsewaGateway;
use App\Services\Payments\KhaltiGateway;
use App\Services\Payments\NullGateway;
use App\Support\ProductionChecks;
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
        $this->app->bind(PaymentGateway::class, fn () => match (config('payments.driver')) {
            'khalti' => new KhaltiGateway,
            'esewa' => new EsewaGateway,
            default => new NullGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Refuse to serve requests with development settings. CLI boots (image build, artisan)
        // are exempt; the container entrypoint runs `php artisan env:check` before starting.
        if (! $this->app->environment('local', 'testing') && ! $this->app->runningInConsole()) {
            $problems = ProductionChecks::fromConfig();
            if ($problems) {
                throw new \RuntimeException("Unsafe production configuration:\n- ".implode("\n- ", $problems));
            }
        }

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
