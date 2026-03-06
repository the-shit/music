<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class FlowCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'flow
        {--duration=60 : Approximate session duration in minutes}
        {--limit= : Number of tracks to queue (overrides --duration)}
        {--json : Output as JSON}';

    protected $description = 'Queue focus/flow state music for deep work';

    public function handle()
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $spotify = app(SpotifyService::class);
        $duration = (int) $this->option('duration');
        $limit = $this->option('limit');

        // Use explicit limit if provided, otherwise calculate from duration (~3.5 min avg per track)
        $trackCount = $limit !== null ? (int) $limit : max(5, (int) ceil($duration / 3.5));

        try {
            $queries = [
                'instrumental focus ambient coding',
                'lo-fi study beats concentration',
                'deep focus electronic ambient',
            ];

            $allTracks = $this->gatherTracks($spotify, $queries, $trackCount);

            if (empty($allTracks)) {
                $this->warn('No flow tracks found.');

                return self::FAILURE;
            }

            $queued = $this->queueTracks($spotify, $allTracks);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'mood' => 'flow',
                    'duration_minutes' => $duration,
                    'queued' => $queued,
                ]));

                return self::SUCCESS;
            }

            $this->info("🧘 Flow mode activated — {$duration} min session");
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
