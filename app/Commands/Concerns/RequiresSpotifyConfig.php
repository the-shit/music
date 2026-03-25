<?php

namespace App\Commands\Concerns;

use App\Services\SpotifyAuthManager;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

trait RequiresSpotifyConfig
{
    protected function ensureConfigured(): bool
    {
        $auth = app(SpotifyAuthManager::class);

        if ($auth->isConfigured()) {
            return true;
        }

        error('Spotify is not configured');
        info('Run "spotify setup" first');

        return false;
    }
}
