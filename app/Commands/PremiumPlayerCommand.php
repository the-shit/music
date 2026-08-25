<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Player\GenreMoodMap;
use App\Player\LyricsProvider;
use App\Player\PlayerRenderer;
use App\Player\PlayerTheme;
use App\Player\PlayerViewModel;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use App\Support\SpotifyRateLimit;
use LaravelZero\Framework\Commands\Command;
use PhpTui\Term\Actions;
use PhpTui\Term\Event;
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

    /** How many playlists the picker overlay requests. */
    private const PLAYLIST_LIMIT = 20;

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

    private const QUEUE = 'queue';

    private const PLAYLIST = 'playlist';

    private const CYCLE_THEME = 'cycle-theme';

    private const LYRICS = 'lyrics';

    private const NONE = 'none';

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery, GenreMoodMap $genreMoodMap, LyricsProvider $lyricsProvider): int
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

        return $this->runLoop($player, $discovery, $genreMoodMap, $lyricsProvider);
    }

    /**
     * The interactive render/input loop. Everything terminal-mutating is wrapped
     * so the terminal is ALWAYS restored, even on error or Ctrl+C.
     */
    private function runLoop(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery, GenreMoodMap $genreMoodMap, LyricsProvider $lyricsProvider): int
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
        // Manual theme override, cycled with `t` (null = Auto, follow the detected
        // mood). Kept SEPARATE from $mood so auto-detection keeps tracking the
        // music underneath — clearing the override (cycling back to Auto) lands on
        // the current track's real mood, not a stale one. The effective theme mood
        // is always ($themeOverride ?? $mood).
        $themeOverride = null;
        $renderer = new PlayerRenderer(PlayerTheme::forMood($mood));
        $lastTrackKey = null;
        $moodByArtist = []; // cache: artist_id → mood, so revisited tracks are free
        $upNext = null;     // the next queued track's "Title — Artist", refreshed on track change

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

        // Which surface we drew last tick: 'panel' | 'search' | 'queue' | 'playlist'.
        // WHY: php-tui's inline renderer only repaints cells that DIFFER from the
        // previous frame. The controls strip carries variation-selector emoji
        // (▶️/⏸️/⏭️/…) whose terminal width is AMBIGUOUS — the exact trap already
        // documented on the progress line and the status footer. When a centered
        // overlay is drawn over the panel and then closed, the cell-width accounting
        // between the overlay frame and the panel frame drifts, so the diff misses
        // cells and leftover overlay glyphs (the modal border + "↑↓ … esc" footer)
        // survive on the controls row. Forcing a full clear ON the surface change
        // resets the back buffer so the next draw repaints EVERY cell — overlays now
        // open AND close cleanly, with no residue. The clear is one frame, only on a
        // transition, so it costs nothing in the steady state.
        $lastSurface = null;

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
            'returnTo' => null,     // self::QUEUE when opened from the queue overlay
        ];

        // Interactive "up next" overlay state. Items are the raw Spotify queue tracks,
        // snapshotted when the overlay opens (the queue rarely shifts under us in the
        // few seconds it's open, and a per-frame refetch would hammer the API).
        // 'status' carries an inline note (e.g. a no-device failure) without closing
        // the overlay, mirroring the search palette / playlist picker.
        $queue = ['active' => false, 'items' => [], 'selected' => 0, 'status' => ''];

        // Playlist picker overlay state. 'status' carries an inline note (e.g. a
        // no-device failure) without closing the overlay, mirroring the palette.
        $playlist = ['active' => false, 'items' => [], 'selected' => 0, 'status' => ''];

        // Lyrics overlay state. 'lines' is the LyricsProvider result for the track
        // the overlay was opened on (null = no lyrics), snapshotted ON OPEN — the
        // provider caches per track, so reopening is free, and fetching once means
        // the loop never does lyrics I/O per frame. 'scroll' is the top visible line.
        $lyrics = ['active' => false, 'track' => null, 'lines' => null, 'scroll' => 0];

        try {
            while ($running) {
                $now = microtime(true);

                // Keep playback state fresh even while the palette is open, so closing
                // search drops straight back into a current now-playing panel.
                if (! $vm instanceof PlayerViewModel || ($now - $lastFetch) >= self::REFRESH_SECONDS) {
                    // Keep the raw payload: the VM is pure and doesn't carry the
                    // artist_id we need to resolve mood.
                    $payload = $this->safePlayback($player);
                    $vm = PlayerViewModel::fromPlayback($payload);
                    // Stamp the 429 breaker state each refresh: when Spotify is
                    // rate-limiting us every poll comes back empty, and the empty
                    // panel must say so instead of "Nothing playing right now".
                    $vm->rateLimitedUntil = SpotifyRateLimit::resumesAt();
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
                                // A manual override (`t`) wins over auto-detection: keep
                                // tracking $mood underneath, but only re-theme the surface
                                // when the user hasn't pinned a vibe.
                                if ($themeOverride === null) {
                                    $renderer = new PlayerRenderer(PlayerTheme::forMood($mood));
                                }
                            }
                            // Refresh the up-next peek on the SAME cadence as mood — once
                            // per track change, never per frame (one extra queue call, not
                            // one per second; guarded so a miss just hides the peek).
                            $upNext = $this->peekUpNext($player);
                        }
                        // Surface the EFFECTIVE mood + up-next peek on the VM (title
                        // badge / status) — the override, when pinned, is what shows.
                        $vm->mood = $themeOverride ?? $mood;
                        $vm->upNext = $upNext;
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

                // Force a full repaint when the surface changes (panel ↔ any overlay)
                // so a closing overlay never leaves residue behind — see $lastSurface.
                $surface = $this->currentSurface($search, $queue, $playlist, $lyrics);
                if ($surface !== $lastSurface) {
                    $display->clear();
                    $lastSurface = $surface;
                }

                $display->draw($this->frame($renderer, $vm, $search, $queue, $playlist, $lyrics));

                // Drain all buffered input each tick (next() is non-blocking). Each
                // overlay consumes keys differently; only one is ever active at a time.
                $events = $terminal->events();
                while (($event = $events->next()) instanceof Event) {
                    if ($search['active']) {
                        $outcome = $this->handleSearchEvent($event, $player, $search);

                        // The palette can be LAYERED over the queue overlay (opened
                        // with `/` from the queue — returnTo). When it closes (esc,
                        // or a successful ⏎ play), drop back onto a FRESH queue
                        // snapshot so a just-queued/just-played track shows, and
                        // clamp the selection to the new length.
                        if (! $search['active'] && $search['returnTo'] === self::QUEUE) {
                            $queue['items'] = $this->safeQueue($player);
                            $queue['selected'] = max(0, min($queue['selected'], count($queue['items']) - 1));
                            $queue['status'] = '';
                        }
                    } elseif ($queue['active']) {
                        $outcome = $this->handleQueueEvent($event, $player, $queue);

                        // `/` from the queue layers the search palette ON TOP (the
                        // queue state stays intact underneath); returnTo records
                        // where to land when the palette closes.
                        if ($outcome === self::SEARCH) {
                            $search = ['active' => true, 'query' => '', 'results' => [], 'selected' => 0, 'status' => '', 'dirty' => false, 'lastQueried' => 0.0, 'returnTo' => self::QUEUE];

                            continue;
                        }
                    } elseif ($playlist['active']) {
                        $outcome = $this->handlePlaylistEvent($event, $player, $playlist);
                    } elseif ($lyrics['active']) {
                        $outcome = $this->handleLyricsEvent($event, $lyrics);
                    } else {
                        $outcome = $this->handleEvent($event, $player, $vm);

                        // `/` opens the palette in-place (no suspend).
                        if ($outcome === self::SEARCH) {
                            $search = ['active' => true, 'query' => '', 'results' => [], 'selected' => 0, 'status' => '', 'dirty' => false, 'lastQueried' => 0.0, 'returnTo' => null];

                            continue;
                        }

                        // `u` opens the interactive up-next queue; snapshot it now.
                        if ($outcome === self::QUEUE) {
                            $queue = ['active' => true, 'items' => $this->safeQueue($player), 'selected' => 0, 'status' => ''];

                            continue;
                        }

                        // `l` opens the playlist picker; snapshot the playlists now.
                        if ($outcome === self::PLAYLIST) {
                            $playlist = ['active' => true, 'items' => $this->safePlaylists($discovery), 'selected' => 0, 'status' => ''];

                            continue;
                        }

                        // `t` cycles the manual theme: Auto → chill → … → sleep → Auto.
                        // Handled HERE (not in runControl) because the override and the
                        // renderer are loop locals; runControl only names the outcome.
                        // Rebuild immediately so the surface re-tints this frame, and
                        // surface the effective mood on the VM so the heading badge
                        // gives instant feedback. Landing back on Auto re-applies the
                        // auto-detected mood that kept tracking underneath.
                        if ($outcome === self::CYCLE_THEME) {
                            $themeOverride = PlayerTheme::nextOverride($themeOverride);
                            $renderer = new PlayerRenderer(PlayerTheme::forMood($themeOverride ?? $mood));
                            if ($vm->hasPlayback) {
                                $vm->mood = $themeOverride ?? $mood;
                            }

                            continue;
                        }

                        // `y` opens the lyrics overlay for the CURRENT track; fetch
                        // (or cache-hit) the lyrics now, once, never per frame.
                        if ($outcome === self::LYRICS) {
                            $lyrics = [
                                'active' => true,
                                'track' => $vm->hasPlayback ? $vm->title : null,
                                'lines' => $this->safeLyrics($lyricsProvider, $vm),
                                'scroll' => 0,
                            ];

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
                'u' => self::QUEUE, // 'u' for up-next, since 'q' is quit
                'l' => self::PLAYLIST, // opens the playlist picker overlay
                't' => self::CYCLE_THEME, // cycles the manual mood theme (Auto → chill → …)
                'y' => self::LYRICS, // lyrics overlay ('y' since 'l' is playlists)
                ' ' => $this->togglePlayback($player, $vm),
                'n' => $this->then(fn () => $player->next()),
                'p' => $this->then(fn () => $player->previous()),
                's' => $this->then(fn (): bool => $player->setShuffle(! $vm->shuffle)),
                'r' => $this->then(fn (): bool => $player->setRepeat($this->nextRepeat($vm->repeat))),
                '+', '=' => $this->then(fn (): bool => $player->setVolume(min(100, ($vm->volume ?? 0) + 10))),
                '-', '_' => $this->then(fn (): bool => $player->setVolume(max(0, ($vm->volume ?? 0) - 10))),
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
     * Choose what to draw this tick: whichever overlay is open (search, queue,
     * playlist, or lyrics), otherwise the now-playing panel (or the empty state).
     * All are drawn into the same inline viewport, so an overlay reads as a
     * centered modal over the player. At most one overlay is ever active at a time.
     *
     * @param  array<string, mixed>  $search
     * @param  array<string, mixed>  $queue
     * @param  array<string, mixed>  $playlist
     * @param  array<string, mixed>  $lyrics
     */
    private function frame(PlayerRenderer $renderer, ?PlayerViewModel $vm, array $search, array $queue, array $playlist, array $lyrics): Widget
    {
        if ($search['active']) {
            return $renderer->searchOverlay($search['query'], $search['results'], $search['selected'], $search['status']);
        }

        if ($queue['active']) {
            return $renderer->queueOverlay($queue['items'], $queue['selected'], $queue['status']);
        }

        if ($playlist['active']) {
            return $renderer->playlistOverlay($playlist['items'], $playlist['selected'], $playlist['status']);
        }

        if ($lyrics['active']) {
            return $renderer->lyricsOverlay($lyrics['track'], $lyrics['lines'], $lyrics['scroll']);
        }

        // The empty state carries the rate-limit notice (when the breaker is
        // open) so a throttled player reads honestly instead of "Nothing playing".
        return ($vm instanceof PlayerViewModel && $vm->hasPlayback) ? $renderer->nowPlaying($vm) : $renderer->empty($vm?->rateLimitNotice());
    }

    /**
     * Name the surface being drawn this tick: the now-playing 'panel' or whichever
     * overlay is open. The loop compares this tick-to-tick and forces a full clear
     * across a change so a closing overlay never leaves residue — see $lastSurface
     * in runLoop() for the WHY. At most one overlay is ever active at a time.
     *
     * @param  array<string, mixed>  $search
     * @param  array<string, mixed>  $queue
     * @param  array<string, mixed>  $playlist
     * @param  array<string, mixed>  $lyrics
     */
    private function currentSurface(array $search, array $queue, array $playlist, array $lyrics): string
    {
        return match (true) {
            $search['active'] => 'search',
            $queue['active'] => 'queue',
            $playlist['active'] => 'playlist',
            $lyrics['active'] => 'lyrics',
            default => 'panel',
        };
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

        // `a` is a dedicated ACTION key here (add the highlighted result to the
        // queue), per the footer "a queue" — so it is intercepted BEFORE the
        // printable-append below and never lands in the query. WHY this trade-off:
        // play-now (⏎) and add-to-queue both needed a binding on the selected row,
        // and a single-letter action reads cleanly in the footer; the cost is that
        // a literal 'a' can't be typed into the query (a future iteration could move
        // this to Tab if that ever matters).
        if ($char === 'a') {
            return $this->queueSelected($player, $search);
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
     * Add the highlighted result to the playback queue and KEEP the palette open
     * (so the user can queue several in a row), surfacing a brief inline confirm.
     * A no-device/API failure shows an inline status instead of crashing the loop —
     * same contract as playSelected().
     *
     * @param  array<string, mixed>  $search
     */
    private function queueSelected(SpotifyPlayerService $player, array &$search): string
    {
        $track = $search['results'][$search['selected']] ?? null;

        if ($track === null || empty($track['uri'])) {
            return self::NONE; // empty query / no results — nothing to queue
        }

        try {
            $player->addToQueue($track['uri']);
        } catch (Throwable) {
            $search['status'] = 'No active device';

            return self::NONE;
        }

        // Brief inline confirm. ASCII '+' (NOT the ➕ emoji) keeps the footer
        // width-stable — the same ambiguous-width trap deliberately avoided on the
        // progress line and the no-device status. Cleared as soon as the user types.
        $search['status'] = '+ queued';

        return self::NONE;
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
     * Handle a keypress while the up-next queue overlay is open: ↑↓ move the
     * highlighted row, ⏎ plays the chosen up-next track (and closes), `/` layers
     * the search palette over the queue (to add tracks in context — closing it
     * returns to a refreshed queue), `n` skips the current track (the queue shifts,
     * so it is re-snapshotted in place), esc closes, Ctrl+C still quits the whole
     * player. A no-device/API failure on play/skip keeps the overlay open with an
     * inline status — same contract as the search palette.
     *
     * @param  array<string, mixed>  $queue
     */
    private function handleQueueEvent(object $event, SpotifyPlayerService $player, array &$queue): string
    {
        if ($event instanceof CodedKeyEvent) {
            return match ($event->code) {
                KeyCode::Esc => $this->closeOverlay($queue),
                KeyCode::Enter => $this->playSelectedQueueTrack($player, $queue),
                KeyCode::Up => $this->scrollOverlay($queue, -1),
                KeyCode::Down => $this->scrollOverlay($queue, 1),
                default => self::NONE,
            };
        }

        if (! $event instanceof CharKeyEvent) {
            return self::NONE;
        }

        return match ($event->char) {
            "\x03" => self::QUIT, // Ctrl+C always quits, even from an overlay
            '/' => self::SEARCH,  // layer the search palette over the queue
            'n' => $this->skipFromQueue($player, $queue),
            default => self::NONE,
        };
    }

    /**
     * Skip the current track from the queue overlay and KEEP it open. The queue
     * shifts when its head starts playing, so the snapshot is refreshed in place
     * (selection clamped) rather than left stale. A no-device/API failure keeps
     * the overlay open with an inline status — same contract as ⏎ play.
     *
     * @param  array<string, mixed>  $queue
     */
    private function skipFromQueue(SpotifyPlayerService $player, array &$queue): string
    {
        try {
            $player->next();
        } catch (Throwable) {
            // Plain text, NO variation-selector emoji (same width trap as elsewhere).
            $queue['status'] = 'No active device';

            return self::NONE;
        }

        $queue['items'] = $this->safeQueue($player);
        $queue['selected'] = max(0, min($queue['selected'], count($queue['items']) - 1));
        $queue['status'] = '';

        return self::REFRESH; // refetch now-playing so the panel reflects the skip
    }

    /**
     * Play the highlighted up-next track and close the queue overlay. Plays the
     * track's own uri (jumping the queue to it), so it mirrors the search palette's
     * playSelected(). A no-device/API failure keeps the overlay open with an inline
     * status instead of crashing the loop.
     *
     * @param  array<string, mixed>  $queue
     */
    private function playSelectedQueueTrack(SpotifyPlayerService $player, array &$queue): string
    {
        $track = $queue['items'][$queue['selected']] ?? null;

        if ($track === null || empty($track['uri'])) {
            return self::NONE; // empty queue / nothing highlighted
        }

        try {
            $player->play($track['uri']);
        } catch (Throwable) {
            // Plain text, NO variation-selector emoji (same width trap as elsewhere).
            $queue['status'] = 'No active device';

            return self::NONE;
        }

        $queue['active'] = false;

        return self::REFRESH; // refetch now-playing so the panel reflects the new track
    }

    /**
     * Handle a keypress while the playlist picker is open: ↑↓ select, ⏎ plays the
     * highlighted playlist (and closes), esc cancels, Ctrl+C quits. A no-device/API
     * failure on play keeps the overlay open with an inline status instead of
     * crashing the loop — same contract as the search palette's playSelected().
     *
     * @param  array<string, mixed>  $playlist
     */
    private function handlePlaylistEvent(object $event, SpotifyPlayerService $player, array &$playlist): string
    {
        if ($event instanceof CodedKeyEvent) {
            return match ($event->code) {
                KeyCode::Esc => $this->closeOverlay($playlist),
                KeyCode::Enter => $this->playSelectedPlaylist($player, $playlist),
                KeyCode::Up => $this->scrollOverlay($playlist, -1),
                KeyCode::Down => $this->scrollOverlay($playlist, 1),
                default => self::NONE,
            };
        }

        if ($event instanceof CharKeyEvent && $event->char === "\x03") {
            return self::QUIT;
        }

        return self::NONE;
    }

    /**
     * Handle a keypress while the lyrics overlay is open: ↑↓ scroll the visible
     * window over the lyric lines, esc closes, Ctrl+C still quits the whole
     * player. No per-line action — lyrics are read, not selected — so this is
     * the simplest of the overlay handlers. Pure state mutation, NO API calls:
     * the lines were snapshotted when the overlay opened.
     *
     * @param  array<string, mixed>  $lyrics
     */
    private function handleLyricsEvent(object $event, array &$lyrics): string
    {
        if ($event instanceof CodedKeyEvent) {
            return match ($event->code) {
                KeyCode::Esc => $this->closeOverlay($lyrics),
                KeyCode::Up => $this->scrollLyrics($lyrics, -1),
                KeyCode::Down => $this->scrollLyrics($lyrics, 1),
                default => self::NONE,
            };
        }

        if ($event instanceof CharKeyEvent && $event->char === "\x03") {
            return self::QUIT;
        }

        return self::NONE;
    }

    /**
     * Slide the lyrics window, clamped so the last page stays full — the offset
     * never scrolls past (line count − visible rows), using the renderer's OWN
     * window constant so the clamp and the rendered slice can't drift apart.
     *
     * @param  array<string, mixed>  $lyrics
     */
    private function scrollLyrics(array &$lyrics, int $delta): string
    {
        $count = is_array($lyrics['lines']) ? count($lyrics['lines']) : 0;
        $maxScroll = max(0, $count - PlayerRenderer::LYRICS_ROWS);
        $lyrics['scroll'] = max(0, min($maxScroll, $lyrics['scroll'] + $delta));

        return self::NONE;
    }

    /**
     * Fetch the current track's lyrics without ever throwing — no playback, no
     * match, or any provider failure all collapse to null, which the overlay
     * renders as the calm "No lyrics found" state. lrclib matches on duration
     * in SECONDS when known (the VM carries ms).
     *
     * @return list<string>|null
     */
    private function safeLyrics(LyricsProvider $provider, ?PlayerViewModel $vm): ?array
    {
        if (! $vm instanceof PlayerViewModel || ! $vm->hasPlayback) {
            return null;
        }

        try {
            return $provider->lines(
                $vm->artist,
                $vm->title,
                $vm->durationMs > 0 ? intdiv($vm->durationMs, 1000) : null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Close an overlay (search/queue/playlist share the 'active' flag convention).
     *
     * @param  array<string, mixed>  $state
     */
    private function closeOverlay(array &$state): string
    {
        $state['active'] = false;

        return self::NONE;
    }

    /**
     * Move the highlighted row in a list overlay, clamped to the available items.
     *
     * @param  array<string, mixed>  $state
     */
    private function scrollOverlay(array &$state, int $delta): string
    {
        $last = max(0, count($state['items']) - 1);
        $state['selected'] = max(0, min($last, $state['selected'] + $delta));

        return self::NONE;
    }

    /**
     * Play the highlighted playlist and close the picker. A no-device/API failure
     * keeps it open with an inline status (never crashes the loop).
     *
     * @param  array<string, mixed>  $playlist
     */
    private function playSelectedPlaylist(SpotifyPlayerService $player, array &$playlist): string
    {
        $selected = $playlist['items'][$playlist['selected']] ?? null;

        if ($selected === null || empty($selected['id'])) {
            return self::NONE; // no playlists / nothing highlighted
        }

        try {
            // playPlaylist takes the playlist ID (not a URI) and returns false on a
            // soft failure (no token/device); guard the hard failures too.
            $ok = $player->playPlaylist($selected['id']);
        } catch (Throwable) {
            $ok = false;
        }

        if (! $ok) {
            $playlist['status'] = 'No active device';

            return self::NONE;
        }

        $playlist['active'] = false;

        return self::REFRESH; // refetch now-playing so the panel reflects the playlist
    }

    /**
     * Fetch the up-next queue without ever throwing — a failure shows an empty
     * overlay rather than killing the loop. Returns just the queued tracks (the
     * raw Spotify track shape); currently-playing is already on the main panel.
     *
     * @return list<array<string, mixed>>
     */
    private function safeQueue(SpotifyPlayerService $player): array
    {
        try {
            $queue = $player->getQueue();

            return array_values(is_array($queue['queue'] ?? null) ? $queue['queue'] : []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Peek at the next queued track for the now-playing "up next" line: "Title —
     * Artist", or null when there is nothing up next / no token / the call fails.
     *
     * WHY guarded + only called on track change: this is one extra queue API call,
     * so (like resolveMood) it must be cheap and crash-proof — a miss simply hides
     * the peek rather than throwing into the draw loop.
     */
    private function peekUpNext(SpotifyPlayerService $player): ?string
    {
        try {
            $queue = $player->getQueue();
            $first = (is_array($queue['queue'] ?? null) ? $queue['queue'] : [])[0] ?? null;

            if (! is_array($first) || empty($first['name'])) {
                return null;
            }

            $artist = $first['artists'][0]['name'] ?? null;

            return $artist ? $first['name'].' — '.$artist : (string) $first['name'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fetch the user's playlists without ever throwing — a failure shows an empty
     * picker rather than crashing the loop.
     *
     * @return list<array<string, mixed>>
     */
    private function safePlaylists(SpotifyDiscoveryService $discovery): array
    {
        try {
            return array_values($discovery->getPlaylists(self::PLAYLIST_LIMIT));
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
