<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class QueueFillCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'queue:fill {--target=5 : Target queue size} {--json : Output as JSON}';

    protected $description = 'Keep the queue topped up using Spotify\'s recommendation engine';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }
        $target = (int) $this->option('target');

        try {
            $queueData = $spotify->getQueue();
            $currentQueue = $queueData['queue'] ?? [];
            $currentlyPlaying = $queueData['currently_playing'] ?? null;
            $queueLength = count($currentQueue);

            if ($queueLength >= $target) {
                if ($this->option('json')) {
                    $this->line(json_encode([
                        'filled' => false,
                        'queue_length' => $queueLength,
                        'target' => $target,
                        'message' => 'Queue already full',
                    ]));
                }

                return self::SUCCESS;
            }

            $needed = $target - $queueLength;

            // Extract seed IDs from currently playing track
            $seedTrackIds = [];
            $seedArtistIds = [];
            if ($currentlyPlaying) {
                if (isset($currentlyPlaying['id'])) {
                    $seedTrackIds[] = $currentlyPlaying['id'];
                }
                if (isset($currentlyPlaying['artists'][0]['id'])) {
                    $seedArtistIds[] = $currentlyPlaying['artists'][0]['id'];
                }
            }

            // Collect URIs already in queue and recently played to avoid duplicates
            $existingUris = [];
            if ($currentlyPlaying) {
                $existingUris[] = $currentlyPlaying['uri'] ?? '';
            }
            foreach ($currentQueue as $item) {
                $existingUris[] = $item['uri'] ?? '';
            }
            foreach ($spotify->getRecentlyPlayed(20) as $recent) {
                $existingUris[] = $recent['uri'] ?? '';
            }

            // getRecommendations auto-falls through to smart discovery
            // when the deprecated /v1/recommendations endpoint returns empty
            $recommendations = $spotify->getRecommendations($seedTrackIds, $seedArtistIds, $needed + 5);

            // If still empty, fall back to improved related tracks
            if (empty($recommendations) && $currentlyPlaying) {
                $artistName = $currentlyPlaying['artists'][0]['name'] ?? null;
                $trackName = $currentlyPlaying['name'] ?? null;

                if ($artistName && $trackName) {
                    $recommendations = $spotify->getRelatedTracks($artistName, $trackName, $needed + 5);
                }
            }

            if (empty($recommendations)) {
                if ($this->option('json')) {
                    $this->line(json_encode([
                        'filled' => false,
                        'queue_length' => $queueLength,
                        'target' => $target,
                        'message' => 'No recommendations available — try playing a track first',
                    ]));
                } else {
                    warning('No recommendations available — try playing a track first');
                }

                return self::SUCCESS;
            }

            $queued = [];
            foreach ($recommendations as $track) {
                if (count($queued) >= $needed) {
                    break;
                }

                if (in_array($track['uri'], $existingUris)) {
                    continue;
                }

                try {
                    $spotify->addToQueue($track['uri']);
                    $existingUris[] = $track['uri'];
                    $queued[] = [
                        'name' => $track['name'],
                        'artist' => $track['artist'],
                    ];
                } catch (\Exception) {
                    continue;
                }
            }

            if ($this->option('json')) {
                $this->line(json_encode([
                    'filled' => true,
                    'added' => count($queued),
                    'queue_length' => $queueLength + count($queued),
                    'target' => $target,
                    'tracks' => $queued,
                ]));
            } else {
                foreach ($queued as $track) {
                    info("➕ Queued: {$track['name']} by {$track['artist']}");
                }
                info('📋 Queue: '.($queueLength + count($queued))."/{$target}");
            }

        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'filled' => false,
                    'error' => $e->getMessage(),
                ]));
            } else {
                error('Failed to fill queue: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
