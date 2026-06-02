<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\search;
use function Laravel\Prompts\warning;

class FindCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'find
                            {query? : Optional initial search term to seed the typeahead}
                            {--queue : Add picks to the queue instead of playing immediately}';

    protected $description = 'Fuzzy typeahead search and play (or queue) tracks on Spotify';

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        // Seed the first search with the optional query argument, then drop it
        // so subsequent loop iterations start from a blank typeahead.
        $seed = (string) $this->argument('query');

        do {
            $uri = search(
                label: '🔎 Search Spotify',
                options: function (string $value) use ($discovery, $seed): array {
                    $query = strlen($value) < 2 ? $seed : $value;

                    if (strlen($query) < 2) {
                        return [];
                    }

                    $options = [];
                    foreach ($discovery->searchMultiple($query, 'track', 12) as $track) {
                        $options[$track['uri']] = sprintf(
                            '🎵 %s — %s (%s)',
                            $track['name'],
                            $track['artist'] ?? 'Unknown',
                            $track['album'] ?? 'Unknown',
                        );
                    }

                    return $options;
                },
                placeholder: 'Type a song, artist, or album…',
                scroll: 12,
            );

            $seed = '';

            if (! $uri) {
                warning('No track selected.');

                break;
            }

            $this->playOrQueue($player, $uri);

            pause();
        } while (confirm(label: 'Search for another?', default: false));

        return self::SUCCESS;
    }

    private function playOrQueue(SpotifyPlayerService $player, string $uri): void
    {
        $queue = $this->option('queue') || confirm(
            label: 'Add to queue instead of playing now?',
            default: false,
        );

        try {
            if ($queue) {
                $player->addToQueue($uri);
                info("➕ Added to queue: {$uri}");
            } else {
                $player->play($uri);
                info("▶️  Playing: {$uri}");
            }
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'device')) {
                warning('No active Spotify device found.');
                info('💡 Run "spotify devices" to see and pick a device, then try again.');
            } else {
                warning('Could not complete that action: '.$e->getMessage());
            }
        }
    }
}
