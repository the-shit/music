<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class CurrentCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'current {--json : Output as JSON}';

    protected $description = 'Show current track';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $current = $spotify->getCurrentPlayback();

        // JSON output mode
        if ($this->option('json')) {
            $output = $current ?: ['is_playing' => false, 'track' => null];
            $this->line(json_encode($output));

            return self::SUCCESS;
        }

        if (! $current) {
            info('🔇 Nothing is currently playing');

            return self::SUCCESS;
        }

        info('🎵 Currently Playing:');
        $this->newLine();

        $this->line("  <fg=cyan>Track:</> {$current['name']}");
        $this->line("  <fg=cyan>Artist:</> {$current['artist']}");
        $this->line("  <fg=cyan>Album:</> {$current['album']}");

        // Format time
        $progressMin = floor($current['progress_ms'] / 60000);
        $progressSec = floor(($current['progress_ms'] % 60000) / 1000);
        $durationMin = floor($current['duration_ms'] / 60000);
        $durationSec = floor(($current['duration_ms'] % 60000) / 1000);

        $progressStr = sprintf('%d:%02d', $progressMin, $progressSec);
        $durationStr = sprintf('%d:%02d', $durationMin, $durationSec);

        $this->line("  <fg=cyan>Progress:</> {$progressStr} / {$durationStr}");

        // Progress bar
        $progress = ($current['duration_ms'] > 0)
            ? round(($current['progress_ms'] / $current['duration_ms']) * 100)
            : 0;
        $barLength = 20;
        $filled = (int) ($progress * $barLength / 100);
        $bar = str_repeat('▓', $filled).str_repeat('░', $barLength - $filled);

        $statusIcon = $current['is_playing'] ? '▶️' : '⏸️';
        $this->line("  {$statusIcon} <fg=cyan>[$bar]</> {$progress}%");

        return self::SUCCESS;
    }
}
