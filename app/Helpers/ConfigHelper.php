<?php

namespace App\Helpers;

class ConfigHelper
{
    /**
     * Get config directory path (PHAR-compatible)
     * Uses config() if available, falls back to default
     */
    public static function configDir(): string
    {
        // Try config first (allows test overrides), fallback to default
        return config('spotify.config_dir')
            ?? ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/spotify-cli';
    }

    /**
     * Get fresh credentials from file (bypasses config cache)
     */
    public static function getCredentials(): array
    {
        $file = self::configDir().'/credentials.json';

        if (! file_exists($file)) {
            return ['client_id' => null, 'client_secret' => null];
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    /**
     * Check if credentials exist
     */
    public static function hasCredentials(): bool
    {
        $creds = self::getCredentials();

        return ! empty($creds['client_id']) && ! empty($creds['client_secret']);
    }

    /**
     * Get token file path
     */
    public static function tokenPath(): string
    {
        return self::configDir().'/token.json';
    }

    /**
     * Get events file path
     */
    public static function eventsPath(): string
    {
        return self::configDir().'/events.jsonl';
    }
}
