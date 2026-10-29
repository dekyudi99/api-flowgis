<?php

namespace App\Providers;

use App\Auth\KeycloakGuard;
use App\Services\KeycloakService;
use Illuminate\Support\Facades\Auth;
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
        Auth::extend('keycloak', function ($app, $name, array $config) {
            return new KeycloakGuard(
                $app->make(KeycloakService::class),
                $app->make('request')
            );
        });
    }
}
