<?php

namespace App\Providers;

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load environment variables from .env file if it exists
        if (file_exists(base_path('.env'))) {
            \Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SpotifyAuthManager::class);

        $this->app->singleton(SpotifyPlayerService::class, function ($app) {
            return new SpotifyPlayerService($app->make(SpotifyAuthManager::class));
        });

        $this->app->singleton(SpotifyDiscoveryService::class, function ($app) {
            return new SpotifyDiscoveryService($app->make(SpotifyAuthManager::class));
        });
    }
}
