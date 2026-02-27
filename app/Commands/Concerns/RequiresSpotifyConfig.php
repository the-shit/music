<?php

namespace App\Commands\Concerns;

trait RequiresSpotifyConfig
{
    /**
     * Ensure Spotify is configured, auto-launching setup if not.
     * Returns true if configured, false if user cancelled setup.
     */
    protected function ensureConfigured(): bool
    {
        if ($this->spotify->isConfigured()) {
            return true;
        }

        // Show error message
        // Use $this->line() because $this->error()/info() go to STDERR which tests don't capture
        $this->line('❌ Spotify is not configured');
        $this->line('💡 Run "spotify setup" first');

        return false;
    }
}
