<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class SeekCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'seek
                            {position : Position in ms, or M:SS format (e.g. 1:30), or +/-seconds (e.g. +10, -5)}';

    protected $description = 'Seek to a position in the current track';

    public function handle(SpotifyService $spotify)
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        try {
            $input = $this->argument('position');
            $positionMs = $this->parsePosition($spotify, $input);

            $spotify->seek($positionMs);

            $seconds = intdiv($positionMs, 1000);
            $formatted = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
            info("Seeked to {$formatted}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            error('Failed to seek: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function parsePosition(SpotifyService $spotify, string $input): int
    {
        // Relative seek: +10 or -5 (seconds)
        if (preg_match('/^([+-])(\d+)$/', $input, $m)) {
            $current = $spotify->getCurrentPlayback();
            $currentMs = $current['progress_ms'] ?? 0;
            $deltaMs = (int) $m[2] * 1000;

            return max(0, $m[1] === '+' ? $currentMs + $deltaMs : $currentMs - $deltaMs);
        }

        // M:SS format
        if (preg_match('/^(\d+):(\d{1,2})$/', $input, $m)) {
            return ((int) $m[1] * 60 + (int) $m[2]) * 1000;
        }

        // Raw milliseconds
        if (ctype_digit($input)) {
            return (int) $input;
        }

        throw new \InvalidArgumentException("Invalid position: {$input}. Use ms, M:SS, +seconds, or -seconds.");
    }
}
