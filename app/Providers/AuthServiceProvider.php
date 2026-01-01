<?php

namespace App\Providers;

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Scope-to-ability mappings for Spotify OAuth scopes.
     */
    protected array $scopeAbilities = [
        // Playback read abilities
        'read-playback' => 'user-read-playback-state',
        'read-currently-playing' => 'user-read-currently-playing',

        // Playback modify abilities
        'modify-playback' => 'user-modify-playback-state',

        // Playlist abilities
        'read-playlists' => ['playlist-read-private', 'playlist-read-collaborative'],

        // Streaming ability
        'streaming' => 'streaming',
    ];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerGates();
    }

    /**
     * Register the Gate definitions for Spotify OAuth scopes.
     */
    protected function registerGates(): void
    {
        // Register individual scope abilities
        foreach ($this->scopeAbilities as $ability => $scopes) {
            Gate::define($ability, function ($user = null) use ($scopes) {
                return $this->hasScope($scopes);
            });
        }

        // Register composite abilities for commands
        Gate::define('spotify:play', function ($user = null) {
            return $this->hasScope('user-modify-playback-state');
        });

        Gate::define('spotify:pause', function ($user = null) {
            return $this->hasScope('user-modify-playback-state');
        });

        Gate::define('spotify:resume', function ($user = null) {
            return $this->hasScope('user-modify-playback-state');
        });

        Gate::define('spotify:skip', function ($user = null) {
            return $this->hasScope('user-modify-playback-state');
        });

        Gate::define('spotify:volume', function ($user = null) {
            return $this->hasScope('user-modify-playback-state');
        });

        Gate::define('spotify:shuffle', function ($user = null) {
            return $this->hasScope(['user-read-playback-state', 'user-modify-playback-state']);
        });

        Gate::define('spotify:repeat', function ($user = null) {
            return $this->hasScope(['user-read-playback-state', 'user-modify-playback-state']);
        });

        Gate::define('spotify:queue', function ($user = null) {
            return $this->hasScope('user-modify-playback-state');
        });

        Gate::define('spotify:current', function ($user = null) {
            return $this->hasScope(['user-read-playback-state', 'user-read-currently-playing']);
        });

        Gate::define('spotify:devices', function ($user = null) {
            return $this->hasScope('user-read-playback-state');
        });

        Gate::define('spotify:player', function ($user = null) {
            return $this->hasScope(['user-read-playback-state', 'user-modify-playback-state']);
        });
    }

    /**
     * Check if the current token has the required scope(s).
     */
    protected function hasScope(string|array $requiredScopes): bool
    {
        $spotify = app(SpotifyService::class);
        $grantedScopes = $spotify->getGrantedScopes();

        if (empty($grantedScopes)) {
            return false;
        }

        $requiredScopes = (array) $requiredScopes;

        foreach ($requiredScopes as $scope) {
            if (! in_array($scope, $grantedScopes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
