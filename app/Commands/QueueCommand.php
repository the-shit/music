<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class QueueCommand extends Command
{
    use ChecksAuthorization;
    protected $signature = 'queue {query : Song, artist, or playlist to add to queue}';

    protected $description = 'Add a song to the Spotify queue (plays after current track)';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Run "spotify setup" to configure Spotify');
            $this->info('💡 Or set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET env vars');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:queue')) {
            return self::FAILURE;
        }

        $query = $this->argument('query');

        $this->info("🎵 Searching for: {$query}");

        try {
            $result = $spotify->search($query);

            if ($result) {
                // Add to queue
                $spotify->addToQueue($result['uri']);

                $this->info("➕ Added to queue: {$result['name']} by {$result['artist']}");
                $this->info('📋 It will play after the current track');

                // Emit queue event
                $this->call('event:emit', [
                    'event' => 'track.queued',
                    'data' => json_encode([
                        'track' => $result['name'],
                        'artist' => $result['artist'],
                        'uri' => $result['uri'],
                        'search_query' => $query,
                    ]),
                ]);
            } else {
                $this->warn("No results found for: {$query}");

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Failed to add to queue: '.$e->getMessage());

            // Emit error event
            $this->call('event:emit', [
                'event' => 'error.queue_failed',
                'data' => json_encode([
                    'command' => 'queue',
                    'error' => $e->getMessage(),
                ]),
            ]);

            return self::FAILURE;
        }

        $this->info('✅ Successfully added to queue!');

        return self::SUCCESS;
    }
}
