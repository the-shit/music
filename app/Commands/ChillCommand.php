<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class ChillCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'chill
        {--limit=10 : Number of tracks to queue}
        {--json : Output as JSON}';

    protected $description = 'Queue chill relaxing music';

    public function handle()
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $spotify = app(SpotifyService::class);

        try {
            $queries = [
                'chill lofi relaxing acoustic',
                'mellow indie calm vibes',
                'soft jazz ambient relaxation',
            ];

            $tracks = $this->gatherTracks($spotify, $queries, (int) $this->option('limit'));

            if (empty($tracks)) {
                $this->warn('No chill tracks found.');

                return self::FAILURE;
            }

            $queued = $this->queueTracks($spotify, $tracks);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'mood' => 'chill',
                    'queued' => $queued,
                ]));

                return self::SUCCESS;
            }

            $this->info('😌 Chill mode activated');
            $this->newLine();
            foreach ($queued as $i => $track) {
                $action = $i === 0 ? '▶️ Now' : '📋 Queue';
                $this->line("  {$action}: <fg=cyan>{$track['name']}</> by {$track['artist']}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function gatherTracks(SpotifyService $spotify, array $queries, int $needed): array
    {
        $tracks = [];
        $seen = [];

        foreach ($queries as $query) {
            if (count($tracks) >= $needed) {
                break;
            }

            $perQuery = (int) ceil($needed / count($queries));
            $results = $spotify->searchMultiple($query, 'track', $perQuery);

            foreach ($results as $track) {
                if (! isset($seen[$track['uri']])) {
                    $seen[$track['uri']] = true;
                    $tracks[] = $track;
                }
            }
        }

        return array_slice($tracks, 0, $needed);
    }

    private function queueTracks(SpotifyService $spotify, array $tracks): array
    {
        // Build dedup set from current queue and recently played
        $excludeUris = [];
        try {
            $queueData = $spotify->getQueue();
            foreach ($queueData['queue'] ?? [] as $item) {
                $excludeUris[$item['uri'] ?? ''] = true;
            }
            if (isset($queueData['currently_playing']['uri'])) {
                $excludeUris[$queueData['currently_playing']['uri']] = true;
            }
            foreach ($spotify->getRecentlyPlayed(20) as $recent) {
                $excludeUris[$recent['uri']] = true;
            }
        } catch (\Exception) {
            // If we can't fetch queue/recent, proceed without dedup
        }

        // Filter out duplicates
        $tracks = array_filter($tracks, fn ($track) => ! isset($excludeUris[$track['uri']]));
        $tracks = array_values($tracks);

        $queued = [];

        foreach ($tracks as $i => $track) {
            if ($i === 0) {
                $spotify->play($track['uri']);
            } else {
                $spotify->addToQueue($track['uri']);
            }
            $queued[] = $track;
        }

        return $queued;
    }
}
