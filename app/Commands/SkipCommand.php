<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class SkipCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'skip
                            {direction? : next or prev (default: next)}
                            {--json : Output as JSON}';

    protected $description = 'Skip to next or previous track';

    public function handle(SpotifyService $spotify)
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $direction = $this->argument('direction') ?? 'next';

        try {
            // Get current track before skipping
            $before = $spotify->getCurrentPlayback();

            if ($direction === 'prev' || $direction === 'previous') {
                $spotify->previous();
                $skippedDirection = 'previous';
                $emoji = '⏮️';
            } else {
                $spotify->next();
                $skippedDirection = 'next';
                $emoji = '⏭️';
            }

            // Show what's playing now
            sleep(1); // Give Spotify a moment to update
            $current = $spotify->getCurrentPlayback();

            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => true,
                    'direction' => $skippedDirection,
                    'previous' => $before ? [
                        'name' => $before['name'],
                        'artist' => $before['artist'],
                        'progress_ms' => $before['progress_ms'] ?? 0,
                    ] : null,
                    'current' => $current ? [
                        'name' => $current['name'],
                        'artist' => $current['artist'],
                        'album' => $current['album'],
                    ] : null,
                ]));
            } else {
                info("{$emoji}  Skipped to {$skippedDirection} track");
                if ($current) {
                    info("🎵 Now playing: {$current['name']} by {$current['artist']}");
                }
            }

            // Emit skip event
            if ($before && ! $this->option('json')) {
                $this->callSilently('event:emit', [
                    'event' => 'track.skipped',
                    'data' => json_encode([
                        'track' => $before['name'],
                        'artist' => $before['artist'],
                        'skip_at' => $before['progress_ms'],
                        'direction' => $skippedDirection,
                    ]),
                ]);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]));
            } else {
                error('❌ '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
