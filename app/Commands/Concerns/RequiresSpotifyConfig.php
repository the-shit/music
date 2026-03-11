<?php

namespace App\Commands\Concerns;

use App\Services\SpotifyService;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

trait RequiresSpotifyConfig
{
    protected function ensureConfigured(): bool
    {
        $spotify = app(SpotifyService::class);

        if ($spotify->isConfigured()) {
            return true;
        }

        error('Spotify is not configured');
        info('Run "spotify setup" first');

        return false;
    }
}
