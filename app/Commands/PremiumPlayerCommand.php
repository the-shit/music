<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Player\GenreMoodMap;
use App\Player\PlayerRenderer;
use App\Player\PlayerTheme;
use App\Player\PlayerViewModel;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;
use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Display\Display;
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
     * Rows reserved for the inline viewport. Tall enough for the two-column body:
     * inner height = VIEWPORT_HEIGHT - 2 borders = 10, which the renderer uses as
     * the SQUARE album-art height (art is ART_COLS=20 wide → 20×20px half-block).
     * The info column's flexible spacer absorbs the extra rows. Keep in lockstep
     * with PlayerRenderer::ART_COLS (= 2 × inner height) so the art stays square.
     */
    private const VIEWPORT_HEIGHT = 12;

    /** Throttle: how often we hit the Spotify API for fresh playback state. */
    private const REFRESH_SECONDS = 1.0;

    /** Input poll cadence (~12fps) so keys feel responsive without busy-spinning. */
    private const POLL_MICROSECONDS = 80_000;

    // Outcomes of handling a key — keeps the loop readable.
    private const QUIT = 'quit';

    private const REFRESH = 'refresh';

    private const SEARCH = 'search';

    private const NONE = 'none';

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery, GenreMoodMap $genreMoodMap): int
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

        return $this->runLoop($player, $discovery, $genreMoodMap);
    }

    /**
     * The interactive render/input loop. Everything terminal-mutating is wrapped
     * so the terminal is ALWAYS restored, even on error or Ctrl+C.
     */
    private function runLoop(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery, GenreMoodMap $genreMoodMap): int
    {
        // HARD GUARD: php-tui's Terminal enables raw mode + a non-blocking stdin
        // event reader. Constructing it without a real interactive TTY (tests,
        // pipes, CI) can leave the shared terminal corrupted and take down the
        // rest of a test suite (SIGHUP / exit 129). The handle() guards already
        // cover this; this is belt-and-braces so NO path builds the Terminal in a
        // non-TTY context.
        if ($this->app->runningUnitTests() || ! defined('STDIN') || ! @stream_isatty(STDIN)) {
            return self::SUCCESS;
        }

        // Mood is resolved from the artist's genres (audio-features is deprecated),
        // and ONLY on track change — never per frame. The renderer is rebuilt with
        // the mood theme when (and only when) the resolved mood actually changes, so
        // the whole surface (border/title/gauges) tints to the music.
        $mood = 'neutral';
        $renderer = new PlayerRenderer(PlayerTheme::forMood($mood));
        $lastTrackKey = null;
        $moodByArtist = []; // cache: artist_id → mood, so revisited tracks are free

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
                    // Keep the raw payload: the VM is pure and doesn't carry the
                    // artist_id we need to resolve mood.
                    $payload = $this->safePlayback($player);
                    $vm = PlayerViewModel::fromPlayback($payload);
                    $lastFetch = $now;

                    // Resolve mood ONLY when the track changes (cheap key compare),
                    // then rebuild the themed renderer only if the mood moved. The
                    // no-playback path skips this entirely and keeps the last theme.
                    if ($vm->hasPlayback) {
                        $trackKey = $payload['uri'] ?? $payload['name'] ?? null;
                        if ($trackKey !== $lastTrackKey) {
                            $lastTrackKey = $trackKey;
                            $resolved = $this->resolveMood($player, $genreMoodMap, $payload['artist_id'] ?? null, $moodByArtist);
                            if ($resolved !== $mood) {
                                $mood = $resolved;
                                $renderer = new PlayerRenderer(PlayerTheme::forMood($mood));
                            }
                        }
                        // Surface the mood on the VM too (title badge / consistency).
                        $vm->mood = $mood;
                    }
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

                    if ($outcome === self::SEARCH) {
                        // Search must suspend the TUI to prompt the user; the loop
                        // owns the terminal/display, so it is driven from here.
                        $this->runSearch($terminal, $display, $player, $discovery);
                        $lastFetch = 0.0;

                        break; // input was drained during the prompt; restart the tick
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
     * Resolve the listening mood from the current artist's genres, cached per
     * artist so the genres API is hit at most once per artist for the session.
     *
     * WHY guarded + cached: this is the only extra API call the player makes, and
     * it must be cheap and crash-proof. Callers invoke it only on track change; a
     * null/blank artist id or any API failure degrades to 'neutral' (the value the
     * theme already renders gracefully), never an exception into the draw loop.
     *
     * @param  array<string, string>  $cache  artist_id → mood (mutated in place)
     */
    private function resolveMood(SpotifyPlayerService $player, GenreMoodMap $genreMoodMap, ?string $artistId, array &$cache): string
    {
        if ($artistId === null || $artistId === '') {
            return 'neutral';
        }

        if (array_key_exists($artistId, $cache)) {
            return $cache[$artistId];
        }

        try {
            $mood = $genreMoodMap->resolveMood($player->getArtistGenres($artistId));
        } catch (Throwable) {
            $mood = 'neutral';
        }

        return $cache[$artistId] = $mood;
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
                '/' => self::SEARCH, // handled by the loop (needs to suspend the TUI)
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
     * `/` search. Suspends the TUI so the prompt renders in a normal terminal,
     * searches tracks, plays the top match, then resumes the live loop.
     *
     * WHY the try/finally: raw mode + hidden cursor + non-blocking STDIN must
     * ALWAYS be restored — if the search throws (no device, network) or the user
     * aborts, the finally puts the terminal back and forces a clean full redraw,
     * so the loop never returns to a half-broken screen. Empty query / no results
     * degrade to a brief status.
     */
    private function runSearch(Terminal $terminal, Display $display, SpotifyPlayerService $player, SpotifyDiscoveryService $discovery): void
    {
        // Suspend the TUI: restore cooked mode + a visible cursor, and CRUCIALLY put
        // STDIN back into blocking mode — php-tui's event reader sets STDIN
        // non-blocking for its poll loop, which would make any prompt read return
        // empty instantly. A minimal fgets() readline is used instead of a prompt
        // library so we don't fight its own terminal/raw-mode handling mid-loop.
        $terminal->disableRawMode();
        $terminal->execute(Actions::cursorShow());
        stream_set_blocking(STDIN, true);

        try {
            // Clear the screen FIRST so the prompt opens on a clean terminal instead
            // of printing on top of the still-rendered player panel — that overlap is
            // what made the search line look stranded mid-panel. The TUI fully repaints
            // via $display->clear() in the finally + the forced refetch on the next tick.
            // (Interim UX fix; the proper centered php-tui search palette is the follow-up.)
            $this->output->write("\033[2J\033[H");
            $this->output->writeln('');
            $this->output->writeln('  🔍  SEARCH');
            $this->output->writeln('  '.str_repeat('─', 40));
            $this->output->write('  Track: ');
            $query = trim((string) fgets(STDIN));

            if ($query === '') {
                return; // nothing entered — drop straight back into the player
            }

            $results = $discovery->searchMultiple($query, 'track', 10);

            if ($results === []) {
                $this->output->writeln('  No results for "'.$query.'"');
                usleep(700_000); // let the message be read before the TUI repaints

                return;
            }

            // Play the top match (search → play in one step keeps the loop simple).
            $track = $results[0];
            $player->play($track['uri']);
            $this->output->writeln('  ▶️  '.($track['name'] ?? 'Unknown').' — '.($track['artist'] ?? 'Unknown'));
            usleep(500_000);
        } catch (Throwable $e) {
            // No active device, rate limit, network blip — surface briefly, never crash.
            $this->output->writeln('  ⚠️  Search unavailable: '.$e->getMessage());
            usleep(700_000);
        } finally {
            // Restore the loop's non-blocking STDIN, re-enter the TUI, force a repaint.
            stream_set_blocking(STDIN, false);
            $terminal->enableRawMode();
            $terminal->execute(Actions::cursorHide());
            $display->clear();
        }
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
