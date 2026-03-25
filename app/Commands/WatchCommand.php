<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyPlayerService;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class WatchCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'watch
        {--interval=10 : Polling interval in seconds}
        {--slack= : Slack webhook URL to post now-playing updates}
        {--json : Output state changes as JSON lines}';

    protected $description = 'Watch playback and stream events (track changes, play/pause)';

    private ?array $lastState = null;

    private ?string $lastTrackUri = null;

    public function handle(SpotifyPlayerService $player): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }
        $interval = max(3, (int) $this->option('interval'));
        $slackWebhook = $this->option('slack') ?: $this->loadSlackWebhook();
        $jsonMode = $this->option('json');

        if (! $jsonMode) {
            info('👀 Watching Spotify playback...');
            if ($slackWebhook) {
                info('📡 Streaming to Slack');
            }
            info("⏱️  Polling every {$interval}s — Ctrl+C to stop");
            $this->newLine();
        }

        // Register signal handler for clean shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($jsonMode): void {
                if (! $jsonMode) {
                    $this->newLine();
                    info('👋 Stopped watching.');
                }
                exit(0);
            });
        }

        while (true) {
            try {
                $current = $player->getCurrentPlayback();
                $this->processState($current, $slackWebhook, $jsonMode);
            } catch (\Exception $e) {
                if (! $jsonMode) {
                    warning("⚠️  API error: {$e->getMessage()}");
                }
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            sleep($interval);
        }
    }

    private function processState(?array $current, ?string $slackWebhook, bool $jsonMode): void
    {
        // Detect track change
        $currentUri = $current['uri'] ?? null;

        // Build a comparable state fingerprint
        $isPlaying = $current['is_playing'] ?? false;
        $trackName = $current['name'] ?? null;

        // Track changed
        if ($trackName && $currentUri !== $this->lastTrackUri) {
            $event = [
                'type' => 'track_changed',
                'uri' => $currentUri,
                'track' => $trackName,
                'artist' => $current['artist'] ?? 'Unknown',
                'album' => $current['album'] ?? 'Unknown',
                'is_playing' => $isPlaying,
                'timestamp' => now()->toIso8601String(),
            ];

            $this->emitEvent('track.changed', $event);

            if ($jsonMode) {
                $this->line(json_encode($event));
            } else {
                $this->line("🎵 <fg=cyan>{$event['track']}</> by {$event['artist']}");
            }

            if ($slackWebhook) {
                $this->postToSlack($slackWebhook, $event);
            }

            $this->lastTrackUri = $currentUri;
        }

        // Play/pause state changed
        $wasPlaying = $this->lastState['is_playing'] ?? null;
        if ($wasPlaying !== null && $isPlaying !== $wasPlaying) {
            $stateEvent = [
                'type' => 'playback_state_changed',
                'is_playing' => $isPlaying,
                'track' => $trackName,
                'timestamp' => now()->toIso8601String(),
            ];

            $eventName = $isPlaying ? 'playback.resumed' : 'playback.paused';
            $this->emitEvent($eventName, $stateEvent);

            if ($jsonMode) {
                $this->line(json_encode($stateEvent));
            } elseif (! $isPlaying) {
                $this->line('⏸️  Paused');
            } elseif ($currentUri === $this->lastTrackUri) {
                // Only show resumed if track didn't change (track change already logged)
                $this->line('▶️  Resumed');
            }
        }

        // Nothing playing
        if (! $current && $this->lastState) {
            $event = [
                'type' => 'playback_stopped',
                'timestamp' => now()->toIso8601String(),
            ];

            $this->emitEvent('playback.stopped', $event);

            if ($jsonMode) {
                $this->line(json_encode($event));
            } else {
                $this->line('🔇 Playback stopped');
            }
        }

        $this->lastState = $current;
    }

    private function emitEvent(string $name, array $data): void
    {
        $this->callSilently('event:emit', [
            'event' => $name,
            'data' => json_encode($data),
        ]);
    }

    private function postToSlack(string $webhookUrl, array $event): void
    {
        try {
            Http::post($webhookUrl, [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => ":musical_note: *{$event['track']}*\n{$event['artist']} — _{$event['album']}_",
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            // Don't let Slack errors kill the watcher
        }
    }

    private function loadSlackWebhook(): ?string
    {
        $configDir = config('spotify.config_dir');
        $configFile = $configDir.'/slack.json';

        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);

            return $config['webhook_url'] ?? null;
        }

        return env('SPOTIFY_SLACK_WEBHOOK');
    }
}
