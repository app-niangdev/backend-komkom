<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Services\AuthCookieService;
use App\Services\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        Auth::extend('jwt', function ($app, $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app['request'],
                $app->make(JwtService::class),
                $app->make(AuthCookieService::class),
            );
        });

        // API publique de la vitrine : le serveur SSR (toutes les visites passent par sa seule IP)
        // s'identifie par une clé partagée et a un quota élevé ; les navigateurs sont limités par IP.
        RateLimiter::for('storefront', function (Request $request) {
            $key = (string) config('storefront.ssr_key');
            if ($key !== '' && hash_equals($key, (string) $request->header('X-Storefront-Key'))) {
                return Limit::perMinute(config('storefront.ssr_rate_per_minute'))->by('storefront-ssr');
            }

            return Limit::perMinute(config('storefront.rate_per_minute'))->by('storefront:' . $request->ip());
        });
    }
}
