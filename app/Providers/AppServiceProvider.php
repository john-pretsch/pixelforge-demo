<?php

namespace App\Providers;

use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            timeout: (int) config('pixelforge.timeout'),
            connectTimeout: (int) config('pixelforge.connect_timeout'),
            pricingCacheTtl: (int) config('pixelforge.pricing_cache_ttl'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-user limits on our side, so one user can't burn the shared
        // Pixelforge account limits (60 req/min, 3 concurrent, 500/day).
        RateLimiter::for('generations', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        // Operators: the profit report. Granted with `php artisan pixelforge:make-admin`.
        Gate::define('admin', fn (User $user) => $user->is_admin);

        RateLimiter::for('estimates', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
