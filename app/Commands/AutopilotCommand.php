<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class AutopilotCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'autopilot
        {--threshold=3 : Refill when queue has fewer than N tracks}
        {--mood=flow : Mood for recommendations (chill/flow/hype)}';

    protected $description = 'Watch playback and auto-refill the queue when it runs low';

    private ?string $lastTrackUri = null;

    /** @var array<string, true> */
    private array $sessionUris = [];

    private ?string $lastRefillTime = null;

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $spotify = app(SpotifyService::class);
        $threshold = max(1, (int) $this->option('threshold'));
        $mood = $this->option('mood');

        if (! in_array($mood, ['chill', 'flow', 'hype'])) {
            warning("Unknown mood '{$mood}' — using 'flow'");
            $mood = 'flow';
        }

        info("Autopilot engaged — mood: {$mood}, threshold: {$threshold}");
        info('Polling every 10s — Ctrl+C to stop');
        $this->newLine();

        // Register signal handler for clean shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->newLine();
                info('Autopilot disengaged.');
                exit(0);
            });
        }

        while (true) {
            try {
                $this->poll($spotify, $threshold, $mood);
            } catch (\Exception $e) {
                warning("API error: {$e->getMessage()}");
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            sleep(10);
        }
    }

    private function poll(SpotifyService $spotify, int $threshold, string $mood): void
    {
        $current = $spotify->getCurrentPlayback();

        if (! $current || ! ($current['is_playing'] ?? false)) {
            $this->renderStatus(null, null, null);

            return;
        }

        $currentUri = $current['uri'] ?? null;
        $trackChanged = $currentUri !== null && $currentUri !== $this->lastTrackUri;

        if ($trackChanged) {
            $this->lastTrackUri = $currentUri;
            $this->line("Now playing: <fg=cyan>{$current['name']}</> by {$current['artist']}");
        }

        // Check queue depth
        $queueData = $spotify->getQueue();
        $queue = $queueData['queue'] ?? [];
        $queueDepth = count($queue);

        // Only refill on track change AND when queue is below threshold
        if ($trackChanged && $queueDepth < $threshold) {
            $this->refill($spotify, $current, $queue, $threshold, $mood);
        }

        $this->renderStatus($current, $queueDepth, $this->lastRefillTime);
    }

    private function refill(SpotifyService $spotify, array $current, array $queue, int $threshold, string $mood): void
    {
        $needed = $threshold - count($queue);

        // Build seed from current track
        $seedTrackIds = [];
        $seedArtistIds = [];

        if (isset($current['uri']) && preg_match('/spotify:track:(.+)/', $current['uri'], $m)) {
            $seedTrackIds[] = $m[1];
        }

        // Add seeds from recent history for variety
        $recentlyPlayed = $spotify->getRecentlyPlayed(5);
        foreach ($recentlyPlayed as $recent) {
            if (count($seedTrackIds) >= 3) {
                break;
            }
            if (preg_match('/spotify:track:(.+)/', $recent['uri'], $m)) {
                if (! in_array($m[1], $seedTrackIds)) {
                    $seedTrackIds[] = $m[1];
                }
            }
        }

        // Build the dedup set: queue + recently played + session history
        $excludeUris = $this->sessionUris;

        if (isset($current['uri'])) {
            $excludeUris[$current['uri']] = true;
        }
        foreach ($queue as $item) {
            $excludeUris[$item['uri'] ?? ''] = true;
        }
        foreach ($recentlyPlayed as $recent) {
            $excludeUris[$recent['uri']] = true;
        }

        // Request extra recommendations to account for dedup filtering
        $recommendations = $spotify->getRecommendations($seedTrackIds, $seedArtistIds, $needed + 10);

        $added = 0;
        foreach ($recommendations as $track) {
            if ($added >= $needed) {
                break;
            }

            if (isset($excludeUris[$track['uri']])) {
                continue;
            }

            try {
                $spotify->addToQueue($track['uri']);
                $this->sessionUris[$track['uri']] = true;
                $excludeUris[$track['uri']] = true;
                $added++;
                $this->line("  Queued: <fg=green>{$track['name']}</> by {$track['artist']}");
            } catch (\Exception) {
                continue;
            }
        }

        if ($added > 0) {
            $this->lastRefillTime = now()->format('H:i:s');
            info("Refilled {$added} tracks ({$mood} mood)");
        } else {
            warning('No fresh recommendations available to add');
        }
    }

    private function renderStatus(?array $current, ?int $queueDepth, ?string $lastRefill): void
    {
        if (! $current) {
            return;
        }

        $track = $current['name'] ?? 'Unknown';
        $artist = $current['artist'] ?? 'Unknown';
        $refillInfo = $lastRefill ? " | Last refill: {$lastRefill}" : '';

        $this->line(
            "<fg=gray>[{$track} by {$artist} | Queue: {$queueDepth}{$refillInfo}]</>"
        );
    }
}
