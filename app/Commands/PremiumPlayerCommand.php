<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Player\PlayerRenderer;
use App\Player\PlayerTheme;
use App\Player\PlayerViewModel;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;
use PhpTui\Tui\DisplayBuilder;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Live php-tui preview of the premium player. Intentionally a SEPARATE command
 * from `player` so we can look at the new GUI without disturbing the working one.
 *
 * WHY this is thin: all the real logic lives in tested, php-tui-free units —
 * {@see PlayerViewModel} maps the API payload, {@see PlayerRenderer} composes the
 * widget tree, {@see PlayerTheme} owns the palette. This command only owns the
 * interaction loop: pull state on an interval, draw, translate keypresses into
 * SpotifyPlayerService calls, and restore the terminal on exit. That keeps the
 * risky raw-mode/event-loop code small and the rest unit-tested.
 */
class PremiumPlayerCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'player:premium';

    protected $description = '💎 Premium Spotify player (php-tui preview)';

    /**
     * Rows reserved for the inline viewport. Sized to the renderer's content
     * (2 borders + 5 info rows + 1 breathing spacer + 1 controls) so there's no
     * dead vertical space below the panel.
     */
    private const VIEWPORT_HEIGHT = 9;

    /** Throttle: how often we hit the Spotify API for fresh playback state. */
    private const REFRESH_SECONDS = 1.0;

    /** Input poll cadence (~12fps) so keys feel responsive without busy-spinning. */
    private const POLL_MICROSECONDS = 80_000;

    // Outcomes of handling a key — keeps the loop readable.
    private const QUIT = 'quit';

    private const REFRESH = 'refresh';

    private const NONE = 'none';

    public function handle(SpotifyPlayerService $player): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        // Raw-mode TUI needs a real interactive terminal; bail clearly otherwise
        // (also keeps the command testable: --no-interaction never enters the loop).
        if (! $this->input->isInteractive()) {
            error('❌ Player requires an interactive terminal');
            info('💡 Run without piping or in a proper terminal');

            return self::FAILURE;
        }

        return $this->runLoop($player);
    }

    /**
     * The interactive render/input loop. Everything terminal-mutating is wrapped
     * so the terminal is ALWAYS restored, even on error or Ctrl+C.
     */
    private function runLoop(SpotifyPlayerService $player): int
    {
        // Mood source is still an open question (audio-features is deprecated for
        // many apps); the theme degrades gracefully on 'neutral' until it's wired.
        $renderer = new PlayerRenderer(PlayerTheme::forMood('neutral'));

        $terminal = Terminal::new();

        // php-tui/term emits PHP 8.4 implicit-nullable deprecations; keep them off
        // the rendered frame. Restored in finally.
        $previousReporting = error_reporting();
        error_reporting($previousReporting & ~E_DEPRECATED);

        $terminal->execute(Actions::cursorHide());
        $terminal->enableRawMode();
        $display = DisplayBuilder::default()->inline(self::VIEWPORT_HEIGHT)->build();

        $running = true;
        $lastFetch = 0.0;
        $vm = null;

        try {
            while ($running) {
                $now = microtime(true);
                if ($vm === null || ($now - $lastFetch) >= self::REFRESH_SECONDS) {
                    $vm = PlayerViewModel::fromPlayback($this->safePlayback($player));
                    $lastFetch = $now;
                }

                $display->draw($vm->hasPlayback ? $renderer->nowPlaying($vm) : $renderer->empty());

                // Drain all buffered input each tick (next() is non-blocking).
                $events = $terminal->events();
                while (($event = $events->next()) !== null) {
                    $outcome = $this->handleEvent($event, $player, $vm);

                    if ($outcome === self::QUIT) {
                        $running = false;
                        break;
                    }

                    if ($outcome === self::REFRESH) {
                        // Force an immediate refetch so the UI reflects the action.
                        $lastFetch = 0.0;
                    }
                }

                usleep(self::POLL_MICROSECONDS);
            }
        } finally {
            $terminal->disableRawMode();
            $terminal->execute(Actions::cursorShow());
            error_reporting($previousReporting);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Fetch playback without ever throwing — a transient API/network error must
     * not kill the loop; we simply fall back to the empty state.
     */
    private function safePlayback(SpotifyPlayerService $player): ?array
    {
        try {
            return $player->getCurrentPlayback();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Translate a php-tui input event into a loop outcome.
     */
    private function handleEvent(object $event, SpotifyPlayerService $player, PlayerViewModel $vm): string
    {
        if ($event instanceof CodedKeyEvent) {
            return $event->code === KeyCode::Esc ? self::QUIT : self::NONE;
        }

        if (! $event instanceof CharKeyEvent) {
            return self::NONE;
        }

        // Ctrl+C reaches us as ETX (0x03) once raw mode swallows the signal.
        if ($event->char === "\x03") {
            return self::QUIT;
        }

        return $this->runControl($event->char, $player, $vm);
    }

    /**
     * Map a key to a playback control. Every API call is guarded so a failure
     * (no active device, rate limit, …) degrades to a no-op instead of crashing.
     */
    private function runControl(string $char, SpotifyPlayerService $player, PlayerViewModel $vm): string
    {
        try {
            return match ($char) {
                'q' => self::QUIT,
                ' ' => $this->togglePlayback($player, $vm),
                'n' => $this->then(fn () => $player->next()),
                'p' => $this->then(fn () => $player->previous()),
                's' => $this->then(fn () => $player->setShuffle(! $vm->shuffle)),
                'r' => $this->then(fn () => $player->setRepeat($this->nextRepeat($vm->repeat))),
                '+', '=' => $this->then(fn () => $player->setVolume(min(100, ($vm->volume ?? 0) + 10))),
                '-', '_' => $this->then(fn () => $player->setVolume(max(0, ($vm->volume ?? 0) - 10))),
                default => self::NONE,
            };
        } catch (Throwable) {
            return self::NONE;
        }
    }

    private function togglePlayback(SpotifyPlayerService $player, PlayerViewModel $vm): string
    {
        $vm->isPlaying ? $player->pause() : $player->resume();

        return self::REFRESH;
    }

    /**
     * Run a control side effect and request a UI refresh.
     */
    private function then(callable $action): string
    {
        $action();

        return self::REFRESH;
    }

    /**
     * Cycle Spotify repeat modes: off → all (context) → one (track) → off.
     */
    private function nextRepeat(string $current): string
    {
        return match ($current) {
            'off' => 'context',
            'context' => 'track',
            default => 'off',
        };
    }
}
