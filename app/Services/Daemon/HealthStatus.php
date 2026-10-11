<?php

declare(strict_types=1);

namespace App\Services\Daemon;

class HealthStatus
{
    /**
     * Connect can report this device as playing after PipeWire drops the
     * stream. That gap is degraded even when the process is up and the log
     * is quiet. A single-track "context is not available" WARN is not this
     * signal and must not flip status on its own.
     */
    public static function resolve(?int $pid, int $totalErrors, ?float $cacheSizeMb, bool $playingWithoutSink): string
    {
        if ($pid === null || $pid <= 0) {
            return 'dead';
        }

        $cacheBloated = $cacheSizeMb !== null && $cacheSizeMb > 500;

        if ($playingWithoutSink || $totalErrors > 10 || $cacheBloated) {
            return 'degraded';
        }

        return 'healthy';
    }
}
