<?php

namespace App\Commands\Concerns;

use Illuminate\Support\Facades\Gate;

trait ChecksAuthorization
{
    /**
     * Check if the current user is authorized for the given ability.
     */
    protected function authorize(string $ability): bool
    {
        return Gate::allows($ability);
    }

    /**
     * Check authorization and output error if not authorized.
     *
     * @return bool True if authorized, false if not
     */
    protected function authorizeOrFail(string $ability, ?string $customMessage = null): bool
    {
        if (! $this->authorize($ability)) {
            $message = $customMessage ?? $this->getAuthorizationErrorMessage($ability);

            if (method_exists($this, 'option') && $this->option('json')) {
                $this->line(json_encode([
                    'error' => true,
                    'message' => $message,
                    'required_scope' => $this->getRequiredScopeForAbility($ability),
                ]));
            } else {
                $this->error("❌ {$message}");
                $this->info('💡 Re-run "spotify login" to grant the required scopes');
            }

            return false;
        }

        return true;
    }

    /**
     * Get a user-friendly error message for authorization failure.
     */
    protected function getAuthorizationErrorMessage(string $ability): string
    {
        $scope = $this->getRequiredScopeForAbility($ability);

        return "Missing required scope: {$scope}";
    }

    /**
     * Get the required Spotify scope for a given ability.
     */
    protected function getRequiredScopeForAbility(string $ability): string
    {
        $scopeMap = [
            'spotify:play' => 'user-modify-playback-state',
            'spotify:pause' => 'user-modify-playback-state',
            'spotify:resume' => 'user-modify-playback-state',
            'spotify:skip' => 'user-modify-playback-state',
            'spotify:volume' => 'user-modify-playback-state',
            'spotify:shuffle' => 'user-read-playback-state, user-modify-playback-state',
            'spotify:repeat' => 'user-read-playback-state, user-modify-playback-state',
            'spotify:queue' => 'user-modify-playback-state',
            'spotify:current' => 'user-read-playback-state, user-read-currently-playing',
            'spotify:devices' => 'user-read-playback-state',
            'spotify:player' => 'user-read-playback-state, user-modify-playback-state',
            'modify-playback' => 'user-modify-playback-state',
            'read-playback' => 'user-read-playback-state',
            'read-currently-playing' => 'user-read-currently-playing',
        ];

        return $scopeMap[$ability] ?? 'unknown';
    }
}
