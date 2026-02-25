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
        {--mood=flow : Mood for recommendations (chill/flow/hype)}
        {--interval=3 : Watch polling interval in seconds}';

    protected $description = 'Auto-refill the queue on every track change (event-driven)';

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
        $interval = max(1, (int) $this->option('interval'));

        if (! in_array($mood, ['chill', 'flow', 'hype'])) {
            warning("Unknown mood '{$mood}' — using 'flow'");
            $mood = 'flow';
        }

        info("Autopilot engaged — mood: {$mood}, threshold: {$threshold}");
        info('Listening for track changes — Ctrl+C to stop');
        $this->newLine();

        // Open a pipe to `watch --json` so we get one JSON line per event
        $php = PHP_BINARY;
        $self = $_SERVER['argv'][0] ?? 'spotify';
        $cmd = escapeshellarg($php).' '.escapeshellarg($self)
                   .' watch --json --interval='.escapeshellarg((string) $interval);

        $pipe = popen($cmd, 'r');

        if (! $pipe) {
            $this->error('Failed to open watch pipe.');

            return self::FAILURE;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($pipe) {
                pclose($pipe);
                $this->newLine();
                info('Autopilot disengaged.');
                exit(0);
            });
        }

        while (! feof($pipe)) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $line = fgets($pipe);

            if ($line === false || trim($line) === '') {
                continue;
            }

            $event = json_decode(trim($line), true);

            if (! is_array($event)) {
                continue;
            }

            // Only act on track changes
            if (($event['type'] ?? null) !== 'track_changed') {
                continue;
            }

            $this->line("Track changed: <fg=cyan>{$event['track']}</> by {$event['artist']}");

            try {
                $this->maybeRefill($spotify, $threshold, $mood);
            } catch (\Exception $e) {
                warning("Refill error: {$e->getMessage()}");
            }
        }

        pclose($pipe);

        return self::SUCCESS;
    }

    private function maybeRefill(SpotifyService $spotify, int $threshold, string $mood): void
    {
        $queueData = $spotify->getQueue();
        $queue = $queueData['queue'] ?? [];
        $queueDepth = count($queue);
        $current = $spotify->getCurrentPlayback();

        $this->line("  Queue depth: {$queueDepth} / threshold: {$threshold}");

        if ($queueDepth >= $threshold) {
            $this->line('  <fg=gray>Queue healthy — no refill needed</>');

            return;
        }

        $this->refill($spotify, $current ?? [], $queue, $threshold, $mood);
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

        // Add mood-specific audio feature targets if the recommendations API supports it
        $moodParams = match ($mood) {
            'chill' => ['target_energy' => 0.3, 'target_valence' => 0.5, 'target_tempo' => 90],
            'hype' => ['target_energy' => 0.9, 'target_valence' => 0.8, 'target_tempo' => 140],
            default => ['target_energy' => 0.6, 'target_valence' => 0.6, 'target_tempo' => 120], // flow
        };

        // Seed from recent history for variety
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

        // Build the dedup set
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

        $recommendations = $spotify->getRecommendations($seedTrackIds, $seedArtistIds, $needed + 10, $moodParams);

        // Fall back to related tracks search if recommendations API returned nothing
        if (empty($recommendations) && isset($current['artist'])) {
            $this->line('  <fg=gray>Recommendations unavailable — falling back to related tracks</>');
            $recommendations = $spotify->getRelatedTracks($current['artist'], $current['name'] ?? '', $needed + 10);
        }

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
            info("Refilled {$added} tracks ({$mood} mood) at {$this->lastRefillTime}");
        } else {
            warning('No fresh recommendations available to add');
        }
    }
}
