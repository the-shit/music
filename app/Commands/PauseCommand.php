<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class PauseCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'pause {--json : Output as JSON}';

    protected $description = 'Pause Spotify playback';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $this->option('json')) {
            info('⏸️  Pausing Spotify playback...');
        }

        try {
            // Get current track before pausing
            $current = $spotify->getCurrentPlayback();

            $spotify->pause();

            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => true,
                    'paused' => true,
                    'track' => $current ? [
                        'name' => $current['name'],
                        'artist' => $current['artist'],
                        'paused_at' => $current['progress_ms'],
                    ] : null,
                ]));
            } else {
                info('✅ Playback paused!');

                // Emit pause event
                if ($current) {
                    $this->callSilently('event:emit', [
                        'event' => 'track.paused',
                        'data' => json_encode([
                            'track' => $current['name'],
                            'artist' => $current['artist'],
                            'paused_at' => $current['progress_ms'],
                        ]),
                    ]);
                }
            }
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ]));
            } else {
                error('❌ Failed to pause: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
