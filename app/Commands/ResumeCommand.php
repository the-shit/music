<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Commands\Concerns\ResolvesDevice;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class ResumeCommand extends Command
{
    use RequiresSpotifyConfig;
    use ResolvesDevice;

    protected $signature = 'resume
                            {--device= : Device name or ID to resume on}
                            {--json : Output as JSON}';

    protected $description = 'Resume Spotify playback from where it was paused';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $deviceName = $this->option('device');
        $resolved = $this->resolveDevice($spotify, $deviceName);
        $deviceId = $resolved['id'] ?? null;

        if ($deviceName && ! $deviceId) {
            error("Device '{$deviceName}' not found");

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            info('▶️  Resuming Spotify playback...');
        }

        try {
            // If device specified, transfer playback first
            if ($deviceId) {
                $spotify->transferPlayback($deviceId, true);
            } else {
                $spotify->resume();
            }

            // Get current track info for event
            $current = $spotify->getCurrentPlayback();

            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => true,
                    'resumed' => true,
                    'device_id' => $deviceId,
                    'track' => $current ? [
                        'name' => $current['name'],
                        'artist' => $current['artist'],
                        'album' => $current['album'],
                    ] : null,
                ]));
                // Still emit the event but suppress output
                $this->callSilently('event:emit', [
                    'event' => 'track.resumed',
                    'data' => json_encode([
                        'track' => $current['name'] ?? null,
                        'artist' => $current['artist'] ?? null,
                        'device_id' => $deviceId,
                    ]),
                ]);
            } else {
                // Emit resume event
                $this->call('event:emit', [
                    'event' => 'track.resumed',
                    'data' => json_encode([
                        'track' => $current['name'] ?? null,
                        'artist' => $current['artist'] ?? null,
                        'device_id' => $deviceId,
                    ]),
                ]);

                if ($current) {
                    info("🎵 Resumed: {$current['name']} by {$current['artist']}");
                }

                info('✅ Playback resumed!');
            }

        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]));
            } else {
                error('Failed to resume: '.$e->getMessage());

                // Emit error event
                $this->call('event:emit', [
                    'event' => 'error.playback_failed',
                    'data' => json_encode([
                        'command' => 'resume',
                        'action' => 'resume',
                        'error' => $e->getMessage(),
                    ]),
                ]);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
