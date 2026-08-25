<?php

namespace App\Services\Daemon;

class Config
{
    /** Pre-hostname default baked into older spotifyd.conf files. */
    private const LEGACY_DEFAULT_DEVICE_NAME = 'Work Mac';

    private string $configDir;

    private string $configFile;

    public function __construct(?string $configDir = null)
    {
        $this->configDir = $configDir ?? $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $this->configDir .= '/.config/spotify-cli';
        $this->configFile = $this->configDir.'/spotifyd.conf';
    }

    public function exists(): bool
    {
        return file_exists($this->configFile);
    }

    public function read(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        return file_get_contents($this->configFile);
    }

    public function deviceName(): ?string
    {
        $contents = $this->read();
        if (! $contents) {
            return null;
        }

        if (preg_match('/device_name\s*=\s*"([^"]+)"/', $contents, $matches)) {
            $name = trim($matches[1]);

            if ($name === '' || strcasecmp($name, self::LEGACY_DEFAULT_DEVICE_NAME) === 0) {
                return null;
            }

            return $name;
        }

        return null;
    }

    public function cachePath(): string
    {
        return $this->configDir.'/cache';
    }
}
