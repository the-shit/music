<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class QueueCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'queue {query : Song, artist, or playlist to add to queue} {--json : Output as JSON}';

    protected $description = 'Add a song to the Spotify queue (plays after current track)';

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $query = $this->argument('query');

        info("🎵 Searching for: {$query}");

        try {
            $result = $discovery->search($query);

            if ($result) {
                // Add to queue
                $player->addToQueue($result['uri']);

                if ($this->option('json')) {
                    $this->line(json_encode([
                        'queued' => true,
                        'track' => $result,
                    ]));

                    return self::SUCCESS;
                }

                info("➕ Added to queue: {$result['name']} by {$result['artist']}");
                info('📋 It will play after the current track');
            } else {
                warning("No results found for: {$query}");

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            error('Failed to add to queue: '.$e->getMessage());

            return self::FAILURE;
        }

        info('✅ Successfully added to queue!');

        return self::SUCCESS;
    }
}
