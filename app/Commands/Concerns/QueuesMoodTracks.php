<?php

namespace App\Commands\Concerns;

use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;

use function Laravel\Prompts\error;

/**
 * Shared device resolve + queue for flow / chill / hype.
 *
 * @property SpotifyPlayerService $player
 * @property SpotifyDiscoveryService $discovery
 */
trait QueuesMoodTracks
{
    use ResolvesDevice;

    /**
     * @return string|false|null false when a named device was requested but missing
     */
    protected function resolvePlaybackDeviceId(SpotifyPlayerService $player): string|false|null
    {
        $deviceName = $this->option('device');
        $deviceName = is_string($deviceName) && $deviceName !== '' ? $deviceName : null;
        $resolved = $this->resolveDevice($player, $deviceName);
        $deviceId = $resolved['id'] ?? null;

        if ($deviceName !== null && $deviceId === null) {
            $this->reportDeviceMissing($deviceName);

            return false;
        }

        return $deviceId;
    }

    protected function reportDeviceMissing(string $deviceName): void
    {
        $message = "Device '{$deviceName}' not found";

        if ($this->option('json')) {
            $this->line(json_encode([
                'success' => false,
                'error' => $message,
            ]));

            return;
        }

        error($message);
    }

    protected function reportFailure(\Exception $e): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));

            return;
        }

        error('❌ '.$e->getMessage());
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<int, array<string, mixed>>
     */
    protected function queueTracks(array $tracks, ?string $deviceId): array
    {
        $excludeUris = [];
        try {
            $queueData = $this->player->getQueue();
            foreach ($queueData['queue'] ?? [] as $item) {
                $excludeUris[$item['uri'] ?? ''] = true;
            }
            if (isset($queueData['currently_playing']['uri'])) {
                $excludeUris[$queueData['currently_playing']['uri']] = true;
            }
            foreach ($this->discovery->getRecentlyPlayed(20) as $recent) {
                $excludeUris[$recent['uri']] = true;
            }
        } catch (\Exception) {
            // If we can't fetch queue/recent, proceed without dedup
        }

        $tracks = array_filter($tracks, fn (array $track): bool => ! isset($excludeUris[$track['uri']]));
        $tracks = array_values($tracks);

        $queued = [];

        foreach ($tracks as $i => $track) {
            if ($i === 0) {
                $this->player->play($track['uri'], $deviceId);
            } else {
                $this->player->addToQueue($track['uri']);
            }
            $queued[] = $track;
        }

        return $queued;
    }
}
