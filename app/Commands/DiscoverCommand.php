<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DiscoverCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'discover
        {query : Search keywords (e.g. "ambient electronic focus")}
        {--seed-from=recent : Seed from recent or top listening data}
        {--limit=10 : Number of results}
        {--json : Output as JSON}';

    protected $description = 'AI-friendly search combining keywords with your listening patterns';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }
        $query = $this->argument('query');
        $seedFrom = $this->option('seed-from');
        $limit = (int) $this->option('limit');

        try {
            // Enrich query with user's listening context
            $enrichedQuery = $this->enrichQuery($spotify, $query, $seedFrom);

            $tracks = $spotify->searchMultiple($enrichedQuery, 'track', $limit);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'query' => $enrichedQuery,
                    'tracks' => $tracks,
                ]));

                return self::SUCCESS;
            }

            info("🔍 Discover: {$enrichedQuery}");
            $this->newLine();

            if (empty($tracks)) {
                warning('No tracks found. Try different keywords.');

                return self::SUCCESS;
            }

            foreach ($tracks as $i => $track) {
                $this->line('  '.($i + 1).". <fg=cyan>{$track['name']}</> by {$track['artist']}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            error('❌ '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function enrichQuery(SpotifyService $spotify, string $query, string $seedFrom): string
    {
        try {
            if ($seedFrom === 'top') {
                $artists = $spotify->getTopArtists('short_term', 3);
            } else {
                $recent = $spotify->getRecentlyPlayed(5);
                // Extract unique artists from recent tracks
                $artists = collect($recent)->pluck('artist')->unique()->take(2)->toArray();

                return $query.' '.implode(' ', $artists);
            }

            if ($artists !== []) {
                $artistNames = collect($artists)->pluck('name')->take(2)->toArray();

                return $query.' '.implode(' ', $artistNames);
            }
        } catch (\Exception $e) {
            // If enrichment fails, just use the raw query
        }

        return $query;
    }
}
