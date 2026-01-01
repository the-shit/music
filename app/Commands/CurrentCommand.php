<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class CurrentCommand extends Command
{
    use ChecksAuthorization;

    protected $signature = 'current {--json : Output as JSON}';

    protected $description = 'Show current track';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Run "spotify setup" first');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:current')) {
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
            $this->info('🔇 Nothing is currently playing');

            // Emit event for checking status when nothing playing
            $this->call('event:emit', [
                'event' => 'playback.status_checked',
                'data' => json_encode([
                    'is_playing' => false,
                    'has_track' => false,
                ]),
            ]);

            return self::SUCCESS;
        }

        // Emit event for viewing current track
        $this->call('event:emit', [
            'event' => 'track.viewed',
            'data' => json_encode([
                'track' => $current['name'],
                'artist' => $current['artist'],
                'album' => $current['album'],
                'progress_ms' => $current['progress_ms'],
                'duration_ms' => $current['duration_ms'],
                'is_playing' => $current['is_playing'],
            ]),
        ]);

        $this->info('🎵 Currently Playing:');
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
