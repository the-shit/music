<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyDiscoveryService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class TopCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'top
        {--type=tracks : Type to show (tracks or artists)}
        {--range=medium_term : Time range (short_term, medium_term, long_term)}
        {--limit=20 : Number of results}
        {--json : Output as JSON}';

    protected $description = 'Show your top tracks or artists';

    public function handle(SpotifyDiscoveryService $discovery): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $type = $this->option('type');
        $range = $this->option('range');
        $limit = (int) $this->option('limit');

        try {
            if ($type === 'artists') {
                $items = $discovery->getTopArtists($range, $limit);

                if ($this->option('json')) {
                    $this->line(json_encode($items));

                    return self::SUCCESS;
                }

                info('🎤 Your Top Artists:');
                $this->newLine();

                foreach ($items as $i => $artist) {
                    $genres = implode(', ', array_slice($artist['genres'], 0, 3));
                    $this->line('  '.($i + 1).". <fg=cyan>{$artist['name']}</>");
                    if ($genres !== '' && $genres !== '0') {
                        $this->line("     Genres: {$genres}");
                    }
                }
            } else {
                $items = $discovery->getTopTracks($range, $limit);

                if ($this->option('json')) {
                    $this->line(json_encode($items));

                    return self::SUCCESS;
                }

                info('🎵 Your Top Tracks:');
                $this->newLine();

                foreach ($items as $i => $track) {
                    $this->line('  '.($i + 1).". <fg=cyan>{$track['name']}</> by {$track['artist']}");
                }
            }

            if (empty($items)) {
                warning('No data found for this time range.');
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            error('❌ '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
