<?php

declare(strict_types=1);

namespace App\Services\Daemon;

class Provision
{
    public function findSpotifyd(): ?string
    {
        $rodioPath = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $rodioPath .= '/.local/bin/spotifyd-rodio';
        if (file_exists($rodioPath)) {
            return $rodioPath;
        }

        $which = trim((string) shell_exec('which spotifyd 2>/dev/null'));

        return $which ?: null;
    }

    public function verifySpotifyd(string $daemonPath): bool
    {
        return file_exists($daemonPath) && is_executable($daemonPath);
    }
}
