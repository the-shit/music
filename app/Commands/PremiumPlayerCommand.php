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
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Widget\Widget;
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

    /** How many tracks the search palette requests + shows. */
    private const SEARCH_LIMIT = 8;

    /**
     * Debounce before re-querying as the user types. WHY: the palette re-searches
     * as the query changes, but firing an API call on every keystroke is wasteful
     * and laggy; we wait for typing to settle this long first.
     */
    private const SEARCH_DEBOUNCE = 0.3;

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

        // In-loop search state (the Raycast-style palette). It is drawn into the SAME
        // inline viewport as the player — no TUI suspend, no fgets — and mutated by
        // handleSearchEvent as the user types/navigates.
        $search = [
            'active' => false,
            'query' => '',
            'results' => [],
            'selected' => 0,
            'status' => '',
            'dirty' => false,       // query changed since the last query → re-search
            'lastQueried' => 0.0,
        ];

        try {
            while ($running) {
                $now = microtime(true);

                // Keep playback state fresh even while the palette is open, so closing
                // search drops straight back into a current now-playing panel.
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

                // Debounced search refresh: re-query only once typing has settled, so a
                // fast typist doesn't fire one API call per keystroke. Guarded so a
                // failure leaves the palette open with no results, never crashes.
                if ($search['active'] && $search['dirty'] && ($now - $search['lastQueried']) >= self::SEARCH_DEBOUNCE) {
                    $search['results'] = $search['query'] === '' ? [] : $this->safeSearch($discovery, $search['query']);
                    $search['selected'] = 0;
                    $search['dirty'] = false;
                    $search['lastQueried'] = $now;
                }

                $display->draw($this->frame($renderer, $vm, $search));

                // Drain all buffered input each tick (next() is non-blocking). Search
                // mode and player mode consume keys differently.
                $events = $terminal->events();
                while (($event = $events->next()) !== null) {
                    if ($search['active']) {
                        $outcome = $this->handleSearchEvent($event, $player, $search);
                    } else {
                        $outcome = $this->handleEvent($event, $player, $vm);

                        // `/` opens the palette in-place (no suspend).
                        if ($outcome === self::SEARCH) {
                            $search = ['active' => true, 'query' => '', 'results' => [], 'selected' => 0, 'status' => '', 'dirty' => false, 'lastQueried' => 0.0];

                            continue;
                        }
                    }

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
                '/' => self::SEARCH, // opens the in-loop search palette (no suspend)
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
     * Choose what to draw this tick: the search palette when it's open, otherwise
     * the now-playing panel (or the empty state). Both are drawn into the same
     * inline viewport, so the palette reads as a centered modal over the player.
     *
     * @param  array<string, mixed>  $search
     */
    private function frame(PlayerRenderer $renderer, ?PlayerViewModel $vm, array $search): Widget
    {
        if ($search['active']) {
            return $renderer->searchOverlay($search['query'], $search['results'], $search['selected'], $search['status']);
        }

        return ($vm !== null && $vm->hasPlayback) ? $renderer->nowPlaying($vm) : $renderer->empty();
    }

    /**
     * Handle a keypress while the search palette is open. Mutates $search in place
     * (query/results/selection/status/active) and returns a loop outcome.
     *
     * WHY in-loop, not a suspend: this is the whole point of the palette — the TUI
     * stays live, keys edit the query and move the selection, and play happens
     * without ever leaving raw mode. Backspace/DEL arrive as either a coded key or
     * a control char depending on the terminal, so both are handled.
     *
     * @param  array<string, mixed>  $search
     */
    private function handleSearchEvent(object $event, SpotifyPlayerService $player, array &$search): string
    {
        if ($event instanceof CodedKeyEvent) {
            return match ($event->code) {
                KeyCode::Esc => $this->closeSearch($search),
                KeyCode::Enter => $this->playSelected($player, $search),
                KeyCode::Up => $this->moveSelection($search, -1),
                KeyCode::Down => $this->moveSelection($search, 1),
                KeyCode::Backspace => $this->backspaceQuery($search),
                default => self::NONE,
            };
        }

        if (! $event instanceof CharKeyEvent) {
            return self::NONE;
        }

        $char = $event->char;

        // Ctrl+C always quits the whole player, even from the palette.
        if ($char === "\x03") {
            return self::QUIT;
        }

        // Some terminals deliver Backspace as DEL (0x7f) / BS (0x08) chars.
        if ($char === "\x7f" || $char === "\x08") {
            return $this->backspaceQuery($search);
        }

        // Append printable input only; ignore stray control characters.
        if ($char !== '' && ord($char[0]) >= 32) {
            $search['query'] .= $char;
            $search['status'] = '';
            $search['dirty'] = true;
        }

        return self::NONE;
    }

    /**
     * @param  array<string, mixed>  $search
     */
    private function closeSearch(array &$search): string
    {
        $search['active'] = false;

        return self::NONE;
    }

    /**
     * Move the highlighted row, clamped to the available results.
     *
     * @param  array<string, mixed>  $search
     */
    private function moveSelection(array &$search, int $delta): string
    {
        $last = max(0, count($search['results']) - 1);
        $search['selected'] = max(0, min($last, $search['selected'] + $delta));

        return self::NONE;
    }

    /**
     * Drop the last (multibyte-safe) character from the query and re-search.
     *
     * @param  array<string, mixed>  $search
     */
    private function backspaceQuery(array &$search): string
    {
        if ($search['query'] !== '') {
            $search['query'] = mb_substr($search['query'], 0, max(0, mb_strlen($search['query']) - 1));
            $search['status'] = '';
            $search['dirty'] = true;
        }

        return self::NONE;
    }

    /**
     * Play the highlighted result and close the palette. A no-device/API failure
     * keeps the palette open with an inline status instead of crashing the loop.
     *
     * @param  array<string, mixed>  $search
     */
    private function playSelected(SpotifyPlayerService $player, array &$search): string
    {
        $track = $search['results'][$search['selected']] ?? null;

        if ($track === null || empty($track['uri'])) {
            return self::NONE; // empty query / no results — nothing to play
        }

        try {
            $player->play($track['uri']);
        } catch (Throwable) {
            // Plain text, NO variation-selector emoji: ⚠️ is ambiguous-width and
            // drifts the footer's cell accounting (same trap as the progress line).
            $search['status'] = 'No active device';

            return self::NONE;
        }

        $search['active'] = false;

        return self::REFRESH; // refetch now-playing so the panel reflects the new track
    }

    /**
     * Search tracks without ever throwing — a failure leaves the palette empty.
     *
     * @return list<array{uri?: string, name?: string, artist?: string}>
     */
    private function safeSearch(SpotifyDiscoveryService $discovery, string $query): array
    {
        try {
            return $discovery->searchMultiple($query, 'track', self::SEARCH_LIMIT);
        } catch (Throwable) {
            return [];
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
