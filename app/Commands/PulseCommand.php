<?php

namespace App\Commands;

use App\Commands\Concerns\RendersPlayback;
use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyPlayerService;
use Carbon\CarbonInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class PulseCommand extends Command
{
    use RendersPlayback;
    use RequiresSpotifyConfig;

    protected $signature = 'pulse
        {--interval=15 : Seconds between heartbeats (minimum 5)}
        {--json : Emit one heartbeat JSON object per tick instead of the board}
        {--reset-at=04:00 : Local HH:MM at which the TODAY counters roll over}
        {--max-ticks=0 : Stop after N ticks (0 = forever); for tests}';

    protected $description = '💓 Live listening vitals — steady heartbeat for observability';

    // --- in-memory "today" accumulators ---
    private int $listenSeconds = 0;

    private int $tracksPlayed = 0;

    private int $skips = 0;

    /** @var array<string, int> track name => play count */
    private array $trackPlays = [];

    /** @var array<string, int> artist name => play count */
    private array $artistPlays = [];

    /** @var list<int> recent activity samples (0-100) for the sparkline */
    private array $sparkline = [];

    // --- last-seen track fingerprint (for change/skip detection) ---
    private ?string $lastUri = null;

    private int $lastProgressMs = 0;

    private int $lastDurationMs = 0;

    private ?CarbonInterface $nextResetAt = null;

    public function handle(SpotifyPlayerService $player): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $interval = max(5, (int) $this->option('interval'));
        $jsonMode = (bool) $this->option('json');
        $maxTicks = max(0, (int) $this->option('max-ticks'));

        // Establish the first rollover boundary.
        $this->maybeReset();

        if (! $jsonMode) {
            info('💓 Pulse — live listening vitals');
            info("⏱️  Heartbeat every {$interval}s — Ctrl+C to stop");
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($jsonMode): void {
                if (! $jsonMode) {
                    $this->newLine();
                    info('👋 Pulse stopped.');
                }
                exit(0);
            });
        }

        $tick = 0;

        while (true) {
            try {
                $this->maybeReset();

                $current = $player->getCurrentPlayback();
                $this->accumulate($current);

                if ($jsonMode) {
                    $this->line((string) json_encode($this->heartbeat($current)));
                } else {
                    $this->renderBoard($current);
                }
            } catch (\Throwable $e) {
                // auto_restart safety: a tick must never throw out of the loop,
                // or the supervisor would crash-loop. Keep the pane alive.
                if ($jsonMode) {
                    $this->line((string) json_encode($this->heartbeat(null)));
                } else {
                    warning("⚠️  Pulse error: {$e->getMessage()}");
                }
            }

            $tick++;
            if ($maxTicks > 0 && $tick >= $maxTicks) {
                break;
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    /**
     * Fold a single playback snapshot into the running "today" totals.
     * No-playback (null) is a calm, first-class state — never an error.
     */
    private function accumulate(?array $current): void
    {
        $uri = $current['uri'] ?? null;
        $playing = (bool) ($current['is_playing'] ?? false);
        $progress = (int) ($current['progress_ms'] ?? 0);
        $duration = (int) ($current['duration_ms'] ?? 0);

        if ($uri !== null && $uri !== $this->lastUri) {
            // A new track started. If the previous one was abandoned well before
            // its end, count it as a skip.
            if ($this->lastUri !== null
                && $this->lastDurationMs > 0
                && $this->lastProgressMs < (int) ($this->lastDurationMs * 0.9)) {
                $this->skips++;
            }

            $this->tracksPlayed++;
            $name = (string) ($current['name'] ?? 'Unknown');
            $artist = (string) ($current['artist'] ?? 'Unknown');
            $this->trackPlays[$name] = ($this->trackPlays[$name] ?? 0) + 1;
            $this->artistPlays[$artist] = ($this->artistPlays[$artist] ?? 0) + 1;
        } elseif ($uri !== null && $uri === $this->lastUri && $playing && $progress > $this->lastProgressMs) {
            // Same track advancing → accrue the elapsed listen time truthfully.
            $this->listenSeconds += (int) round(($progress - $this->lastProgressMs) / 1000);
        }

        // Activity sparkline proxy (no audio-features API is exposed by the
        // player service, so we sample volume while playing, 0 while idle).
        $this->sparkline[] = $playing ? (int) ($current['device']['volume_percent'] ?? 50) : 0;
        if (count($this->sparkline) > 40) {
            array_shift($this->sparkline);
        }

        $this->lastUri = $uri;
        $this->lastProgressMs = $progress;
        $this->lastDurationMs = $duration;
    }

    /**
     * The exact --json heartbeat schema (scratchpad #49, section A).
     *
     * @return array<string, mixed>
     */
    private function heartbeat(?array $current): array
    {
        return [
            'type' => 'pulse',
            'ts' => now()->toIso8601String(),
            'is_playing' => (bool) ($current['is_playing'] ?? false),
            'track' => $current['name'] ?? null,
            'artist' => $current['artist'] ?? null,
            'uri' => $current['uri'] ?? null,
            'progress_ms' => (int) ($current['progress_ms'] ?? 0),
            'duration_ms' => (int) ($current['duration_ms'] ?? 0),
            'device' => $current['device']['name'] ?? null,
            'volume' => isset($current['device']['volume_percent'])
                ? (int) $current['device']['volume_percent']
                : null,
            'today' => [
                'listen_seconds' => $this->listenSeconds,
                'tracks_played' => $this->tracksPlayed,
                'skips' => $this->skips,
                'top_track' => $this->topKey($this->trackPlays),
                'top_artist' => $this->topKey($this->artistPlays),
            ],
        ];
    }

    private function renderBoard(?array $current): void
    {
        $this->clearScreen();
        $this->newLine();
        $this->line('💓 <fg=magenta>PULSE</> — live listening vitals');
        $this->line(str_repeat('─', 53));

        if (! $current) {
            $this->line('⏸️  <fg=gray>NOW</>   Nothing playing — standing by');
        } else {
            $name = (string) ($current['name'] ?? 'Unknown');
            $artist = (string) ($current['artist'] ?? 'Unknown');
            $this->line("🎵 <fg=cyan>NOW</>   {$name} — {$artist}");
            $this->line('      '.$this->formatProgress(
                (int) ($current['progress_ms'] ?? 0),
                (int) ($current['duration_ms'] ?? 0)
            ));

            if (isset($current['device']['volume_percent'])) {
                $this->line('      '.$this->formatVolume((int) $current['device']['volume_percent']));
            }

            $modes = $this->formatPlaybackModes(
                (bool) ($current['shuffle_state'] ?? false),
                (string) ($current['repeat_state'] ?? 'off')
            );
            if ($modes !== '') {
                $this->line('      '.$modes);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '📊 <fg=yellow>TODAY</> %s listened · %d plays · %d skips',
            $this->humanizeSeconds($this->listenSeconds),
            $this->tracksPlayed,
            $this->skips
        ));
        $this->line(sprintf(
            '🏆 <fg=green>TOP</>   %s — %s',
            $this->topKey($this->trackPlays) ?? '—',
            $this->topKey($this->artistPlays) ?? '—'
        ));
        $this->line('📈 '.$this->renderSparkline());
        $this->newLine();
    }

    /**
     * Roll the TODAY counters over once the configured reset time passes.
     */
    private function maybeReset(): void
    {
        if ($this->nextResetAt === null) {
            $this->nextResetAt = $this->computeNextReset();

            return;
        }

        if (now()->greaterThanOrEqualTo($this->nextResetAt)) {
            $this->listenSeconds = 0;
            $this->tracksPlayed = 0;
            $this->skips = 0;
            $this->trackPlays = [];
            $this->artistPlays = [];

            while (now()->greaterThanOrEqualTo($this->nextResetAt)) {
                $this->nextResetAt = $this->nextResetAt->copy()->addDay();
            }
        }
    }

    private function computeNextReset(): CarbonInterface
    {
        [$hour, $minute] = array_pad(explode(':', (string) $this->option('reset-at')), 2, '0');

        $candidate = now()->setTime((int) $hour, (int) $minute, 0);
        if (now()->greaterThanOrEqualTo($candidate)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function topKey(array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function humanizeSeconds(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        }

        return sprintf('%ds', $secs);
    }

    private function renderSparkline(): string
    {
        if ($this->sparkline === []) {
            return '—';
        }

        $blocks = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $out = '';
        foreach ($this->sparkline as $value) {
            $clamped = max(0, min(100, $value));
            $idx = (int) floor($clamped / 100 * (count($blocks) - 1));
            $out .= $blocks[$idx];
        }

        return $out;
    }
}
