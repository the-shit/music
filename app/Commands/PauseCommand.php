<?php

namespace App\Commands;

use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class PauseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pause {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause Spotify playback';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET env vars');
            $this->info('💡 Then run "music login" to authenticate');

            return self::FAILURE;
        }

        if (!$this->option('json')) {
            $this->info('⏸️  Pausing Spotify playback...');
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
                        'paused_at' => $current['progress_ms']
                    ] : null
                ]));
            } else {
                $this->info('✅ Playback paused!');
                
                // Emit pause event
                if ($current) {
                    $this->call('event:emit', [
                        'event' => 'track.paused',
                        'data' => json_encode([
                            'track' => $current['name'],
                            'artist' => $current['artist'],
                            'paused_at' => $current['progress_ms'],
                        ]),
                    ], true);
                }
            }
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
            } else {
                $this->error('❌ Failed to pause: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
