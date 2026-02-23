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

    /**
     * Get webhook configuration
     */
    public static function getWebhookConfig(): array
    {
        $file = self::configDir().'/webhook.json';

        if (! file_exists($file)) {
            return ['url' => null, 'secret' => null, 'enabled' => false];
        }

        return json_decode(file_get_contents($file), true) ?? [];
    }

    /**
     * Check if webhook is configured and enabled
     */
    public static function hasWebhook(): bool
    {
        $config = self::getWebhookConfig();

        return ! empty($config['url']) && ! empty($config['secret']) && ($config['enabled'] ?? false);
    }

    /**
     * Save webhook configuration
     */
    public static function saveWebhookConfig(array $config): void
    {
        $dir = self::configDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir.'/webhook.json';
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT));
        chmod($file, 0600);
    }

    /**
     * Get webhook error log path
     */
    public static function webhookErrorLogPath(): string
    {
        return self::configDir().'/webhook-errors.log';
    }
}
