<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class VolumeCommand extends Command
{
    use ChecksAuthorization;
    protected $signature = 'volume 
                            {level? : Volume level (0-100) or +/- for relative change}
                            {--json : Output as JSON}';

    protected $description = 'Control Spotify volume';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Run "spotify setup" first');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:volume')) {
            return self::FAILURE;
        }

        $level = $this->argument('level');

        // If no level provided, show current volume (handle "0" as valid input)
        if ($level === null || $level === '') {
            $current = $spotify->getCurrentPlayback();

            if (! $current || ! isset($current['device'])) {
                if ($this->option('json')) {
                    $this->line(json_encode(['error' => 'No active device found']));
                } else {
                    $this->error('❌ No active device found');
                    $this->info('💡 Start playing something first');
                }

                return self::FAILURE;
            }

            $volume = $current['device']['volume_percent'] ?? 0;

            if ($this->option('json')) {
                $this->line(json_encode(['volume' => $volume]));
            } else {
                $this->info("🔊 Current volume: {$volume}%");
                $this->showVolumeBar($volume);
            }

            return self::SUCCESS;
        }

        // Parse volume level
        $newVolume = $this->parseVolumeLevel($level, $spotify);

        if ($newVolume === null) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => 'Invalid volume level']));
            } else {
                $this->error('❌ Invalid volume level. Use 0-100 or +/- for relative change');
            }

            return self::FAILURE;
        }

        // Set the volume
        $result = $spotify->setVolume($newVolume);

        if (! $result) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => 'Failed to set volume']));
            } else {
                $this->error('❌ Failed to set volume');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['volume' => $newVolume, 'success' => true]));
            // Still emit the event but suppress output
            $this->callSilently('event:emit', [
                'event' => 'volume.changed',
                'data' => json_encode([
                    'volume' => $newVolume,
                ]),
            ]);
        } else {
            // Emit event
            $this->call('event:emit', [
                'event' => 'volume.changed',
                'data' => json_encode([
                    'volume' => $newVolume,
                ]),
            ]);
            $icon = $this->getVolumeIcon($newVolume);
            $this->info("{$icon} Volume set to {$newVolume}%");
            $this->showVolumeBar($newVolume);
        }

        return self::SUCCESS;
    }

    private function parseVolumeLevel(string $level, SpotifyService $spotify): ?int
    {
        // Handle relative changes
        if (str_starts_with($level, '+') || str_starts_with($level, '-')) {
            $current = $spotify->getCurrentPlayback();
            if (! $current || ! isset($current['device'])) {
                return null;
            }

            $currentVolume = $current['device']['volume_percent'] ?? 50;
            $change = (int) $level;
            $newVolume = $currentVolume + $change;
        } else {
            // Absolute value
            $newVolume = (int) $level;
        }

        // Clamp to 0-100
        return max(0, min(100, $newVolume));
    }

    private function showVolumeBar(int $volume): void
    {
        $barLength = 20;
        $filled = (int) ($volume * $barLength / 100);
        $bar = str_repeat('▓', $filled).str_repeat('░', $barLength - $filled);

        $this->line("  <fg=cyan>[$bar]</> {$volume}%");
    }

    private function getVolumeIcon(int $volume): string
    {
        return match (true) {
            $volume === 0 => '🔇',
            $volume <= 33 => '🔈',
            $volume <= 66 => '🔉',
            default => '🔊',
        };
    }
}
