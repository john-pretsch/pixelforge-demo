<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
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
        //
    }
}
