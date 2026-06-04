<?php

declare(strict_types=1);

namespace App\Player;

use App\Commands\Concerns\RendersPlayback;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GaugeWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;

/**
 * Composes the premium player's now-playing surface from real php-tui widgets.
 *
 * WHY: This is the visual lane — it turns a {@see PlayerViewModel} (pure data)
 * plus a {@see PlayerTheme} (colours/glyphs) into a renderable widget tree, and
 * nothing else. It owns NO playback logic and NO interaction; the integrator
 * loop calls nowPlaying()/empty() and draws the result. Keeping composition here
 * means the layout is exercised by buffer-based unit tests rather than by eyeballing
 * a live terminal.
 *
 * Layout is delegated entirely to php-tui's Grid/Constraint engine — there is no
 * hand-rolled column math or sprintf padding. The only width concession is
 * truncateDisplay() on free-text lines, a soft guard so a pathologically long
 * title can't dominate the panel; php-tui still clips and positions everything.
 */
final class PlayerRenderer
{
    // Reused solely for truncateDisplay(); no rendering logic is taken from it.
    use RendersPlayback;

    /**
     * Soft cap for free-text lines (track/artist/album). NOT layout math — it
     * only stops a 300-char title from swamping the surface before php-tui's
     * own clipping kicks in. Sized to sit INSIDE the (art-narrowed) info column at
     * a standard terminal width so the truncation ellipsis is visible rather than
     * clipped off; still generous enough that normal titles are never touched.
     */
    private const TEXT_WIDTH = 48;

    /**
     * Width (cells) of the LEFT album-art column. Album art is a modest accent,
     * not the centerpiece, so it takes a fixed slice and the info panel keeps the
     * rest. MUST stay = 2 × (inline viewport inner height) so the half-block grid
     * (pixel size = cols × rows*2) renders SQUARE art — see PremiumPlayerCommand's
     * VIEWPORT_HEIGHT (12 → inner 10 → 20×20px). Tune the two together.
     */
    private const ART_COLS = 20;

    /** Max search results shown in the palette — fits the inline viewport height. */
    private const SEARCH_RESULTS = 8;

    /**
     * Fixed cell width of the progress meter. Fixed (not flexible) because we render
     * the bar's TRACK ourselves — see progressBar() — which needs a known length.
     * Sits comfortably inside the art-narrowed info column beside the time label.
     */
    private const PROGRESS_BAR_WIDTH = 24;

    private readonly AlbumArtRenderer $art;

    public function __construct(private readonly PlayerTheme $theme, ?AlbumArtRenderer $art = null)
    {
        // Defaulted so existing call sites (and tests) construct with just a theme;
        // the art renderer is dependency-free and degrades to a placeholder on its own.
        $this->art = $art ?? new AlbumArtRenderer;
    }

    /**
     * The full now-playing panel: a mood-framed Block containing track metadata,
     * progress + volume gauges, and a controls hint strip.
     */
    public function nowPlaying(PlayerViewModel $vm): Widget
    {
        $theme = $this->theme;

        // The key legend is static (no live state, no VS-16 emoji) so it stays
        // readable and width-stable; live shuffle/repeat state lives in statusRow()
        // near the gauges instead — see controlsLines()/statusRow().
        $controls = ParagraphWidget::fromLines(...$this->controlsLines());

        // The now-playing info rows (track…status, + an optional up-next peek). Built
        // once and placed by infoColumn() so both body layouts share identical content
        // and the same centre/fill padding behaviour.
        $rows = $this->infoWidgets($vm);

        // LAYOUT IS CONDITIONAL ON REAL ART. Album art is an accent, not scaffolding:
        // when there is a genuine, decodable cover we split into two columns; when
        // there is NOT (no/empty url, or a fetch/decode failure) we render the clean
        // single-column panel instead of a dead placeholder block. Asking hasArt()
        // first also primes the decode cache that render() reuses — one fetch, not two.
        $body = $this->art->hasArt($vm->albumArtUrl)
            ? $this->twoColumnBody($vm, $rows, $controls)
            : $this->singleColumnBody($rows, $controls);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($theme->borderStyle())
            ->titles(Title::fromString($this->nowPlayingTitle()))
            ->titleStyle($theme->heading())
            ->widget($body);
    }

    /**
     * The now-playing info rows, top to bottom: hero track title, artist, album,
     * progress, volume, the device/shuffle/repeat status line, and — when the loop
     * has resolved a next track — a one-line "up next" peek. Returned as a flat list
     * so the body layouts can place a VARIABLE number of rows (the peek is optional).
     *
     * @return list<Widget>
     */
    private function infoWidgets(PlayerViewModel $vm): array
    {
        $theme = $this->theme;

        // The track title is the hero line: bold AND mood-accent coloured so it
        // clearly outranks the white artist and dim album beneath it.
        $rows = [
            ParagraphWidget::fromString($this->truncateDisplay($vm->title, self::TEXT_WIDTH))
                ->style($theme->accent()->addModifier(Modifier::BOLD)),
            ParagraphWidget::fromString(
                $theme->icon('artist').' '.$this->truncateDisplay($vm->artist, self::TEXT_WIDTH)
            )->style($theme->text()),
            ParagraphWidget::fromString(
                $theme->icon('album').' '.$this->truncateDisplay($vm->album, self::TEXT_WIDTH)
            )->style($theme->dim()),
            $this->progressRow($vm),
            $this->volumeRow($vm),
            $this->statusRow($vm),
        ];

        // Optional up-next peek — only when the loop has resolved a next track.
        $upNext = $this->upNextRow($vm);
        if ($upNext !== null) {
            $rows[] = $upNext;
        }

        return $rows;
    }

    /**
     * Stack the info rows with flexible padding inside whatever area they're given.
     *
     * WHY the conditional centring: a short block (no up-next peek) is CENTRED — a
     * flexible spacer above AND below — so the leftover viewport rows read as balanced
     * padding rather than one dead gap (the audit's "blank middle"). A taller block
     * (with the peek) nearly fills the area, so it is top-aligned with a single
     * trailing spacer — which both looks full and GUARANTEES it can't overflow the
     * cramped 12-row inline viewport (two flexible spacers + 7 rows + the legend would
     * not fit). Anything pinned below (the legend) stays put either way.
     *
     * @param  list<Widget>  $rows
     */
    private function infoColumn(array $rows): Widget
    {
        $constraints = [];
        $widgets = [];

        // Centre short blocks; top-align tall ones (see WHY above).
        if (count($rows) <= 6) {
            $constraints[] = Constraint::min(1);
            $widgets[] = ParagraphWidget::fromString('');
        }

        foreach ($rows as $row) {
            $constraints[] = Constraint::length(1);
            $widgets[] = $row;
        }

        $constraints[] = Constraint::min(1); // trailing spacer fills the remainder
        $widgets[] = ParagraphWidget::fromString('');

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(...$widgets);
    }

    /**
     * The single-column now-playing body — the info column with the controls legend
     * pinned full-width beneath it. The default when there is no album art.
     *
     * @param  list<Widget>  $rows
     */
    private function singleColumnBody(array $rows, Widget $controls): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(1),     // info column (centres / fills via infoColumn)
                Constraint::length(2),  // two-line key legend, pinned to the bottom
            )
            ->widgets(
                $this->infoColumn($rows),
                $controls,
            );
    }

    /**
     * The two-column body — a modest album-art accent on the LEFT, the now-playing
     * info on the RIGHT, and the controls legend spanning FULL width beneath both
     * (it is long and would clip in the narrow info column). Only used when hasArt().
     *
     * @param  list<Widget>  $rows
     */
    private function twoColumnBody(PlayerViewModel $vm, array $rows, Widget $controls): Widget
    {
        // The art renderer rescales to whatever area the layout grants it, so the
        // fixed ART_COLS slice is all the sizing it needs. hasArt() already proved
        // the cover decodes, so render() here returns real art (cache hit), never
        // the placeholder. infoColumn() centres / fills the info against the art.
        $columns = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::length(self::ART_COLS), // album art
                Constraint::length(2),              // gutter between art and info
                Constraint::min(1),                 // info takes the rest
            )
            ->widgets(
                $this->art->render($vm->albumArtUrl ?? '', self::ART_COLS, (int) (self::ART_COLS / 2)),
                ParagraphWidget::fromString(''),
                $this->infoColumn($rows),
            );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(1),     // art + info region
                Constraint::length(2),  // two-line key legend, pinned full-width to the bottom
            )
            ->widgets(
                $columns,
                $controls,
            );
    }

    /**
     * Build the now-playing title, mood-aware and never redundant.
     *
     * WHY: the neutral mood's badge IS the music note, so the old unconditional
     * "{music} NOW PLAYING · {badge}" rendered "🎵 NOW PLAYING · 🎵". For neutral
     * we drop the trailing badge entirely; for a real mood we lead with the mood
     * icon and trail with its name → "😌 NOW PLAYING · chill".
     */
    private function nowPlayingTitle(): string
    {
        $theme = $this->theme;

        if ($theme->mood() === 'neutral') {
            return ' '.$theme->icon('music').' NOW PLAYING ';
        }

        // moodBadge() is "<moodIcon> <mood>"; take just the icon for the lead and
        // mood() for the trailing name (mood icons/names never contain a space).
        $moodIcon = explode(' ', $theme->moodBadge(), 2)[0];

        return ' '.$moodIcon.' NOW PLAYING · '.$theme->mood().' ';
    }

    /**
     * The calm nothing-playing panel. Same mood frame, centred guidance text —
     * so an idle player still feels like part of the surface, not an error.
     */
    public function empty(): Widget
    {
        $theme = $this->theme;

        $body = ParagraphWidget::fromLines(
            Line::fromString(''),
            Line::fromSpans(Span::styled('Nothing playing right now', $theme->text())),
            Line::fromString(''),
            Line::fromSpans(Span::styled('Press / to search, or start playback on Spotify', $theme->dim())),
        )->alignment(HorizontalAlignment::Center);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($theme->borderStyle())
            ->titles(Title::fromString(' '.$theme->icon('music').' NOW PLAYING '))
            ->titleStyle($theme->heading())
            ->widget($body);
    }

    /**
     * The Raycast-style search palette: a centered, mood-framed modal with a live
     * query line, a selectable results list, and a key hint footer.
     *
     * WHY this lives here (not in the command): it is pure composition over plain
     * data (the query string, the result rows, the selected index), so it is
     * unit-testable on a buffer exactly like nowPlaying(). The command owns only
     * the in-loop key handling that mutates that data; this turns a snapshot of it
     * into a widget. NO suspending the TUI, NO fgets — the palette is drawn in the
     * same inline viewport as the player.
     *
     * @param  list<array{uri?: string, name?: string, artist?: string}>  $results
     */
    public function searchOverlay(string $query, array $results, int $selectedIndex, string $status = ''): Widget
    {
        $theme = $this->theme;

        // Live query line with a block cursor so it reads as a focused input.
        $input = ParagraphWidget::fromLines(Line::fromSpans(
            Span::styled('› ', $theme->accent()),
            Span::styled($query, $theme->text()),
            Span::styled('▌', $theme->accent()),
        ));

        // Body: the results list, or a hint/no-matches line.
        $bodyLines = [];
        if ($query === '') {
            // Empty query → just the input + a hint, no list (per the spec).
            $bodyLines[] = Line::fromSpans(Span::styled('Type to search tracks…', $theme->dim()));
        } elseif ($results === []) {
            $bodyLines[] = Line::fromSpans(Span::styled('No matches', $theme->dim()));
        } else {
            // Shared selection renderer — identical ▶ marker + highlight as the queue
            // and playlist overlays, so the three feel like one component (search's
            // artist key is flat `artist`; queue/playlist nest it differently, so we
            // pre-build labels and hand the same list builder to all three).
            $labels = array_map(
                fn (array $track): string => $this->truncateDisplay(
                    ($track['name'] ?? 'Unknown').'  —  '.($track['artist'] ?? 'Unknown'),
                    self::TEXT_WIDTH,
                ),
                array_values($results),
            );
            $bodyLines = $this->windowedSelectableList($labels, $selectedIndex);
        }

        // Footer: unified `↑↓ select · ⏎ <act> · esc close` shape (search adds the
        // extra `a queue` action), prefixed with any inline status / "+ queued".
        $footer = $this->modalFooter('↑↓ select · ⏎ play · a queue · esc close', $status);

        // WHY a Grid (not one Paragraph): with a full 8-row result list the footer
        // would be pushed past the modal's bottom and clipped — taking any inline
        // status with it. Pinning input (top) and footer (bottom) as fixed rows and
        // giving the list the flexible middle keeps the hint/status ALWAYS visible.
        $contents = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1), // query input
                Constraint::min(1),    // results / hint
                Constraint::length(1), // footer (pinned to the bottom)
            )
            ->widgets(
                $input,
                ParagraphWidget::fromLines(...($bodyLines === [] ? [Line::fromString('')] : $bodyLines)),
                ParagraphWidget::fromLines(Line::fromSpans(Span::styled($footer, $theme->dim()))),
            );

        return $this->centeredModal(' '.$theme->icon('search').' Search ', $contents);
    }

    /**
     * Interactive "up next" overlay: a centered modal listing the queued tracks
     * (track — artist), the highlighted row moved with ↑↓, ⏎ to play the chosen
     * up-next track, esc to close. An inline status (e.g. a no-device note) is
     * surfaced in the footer without closing the overlay, like the palette/picker.
     *
     * WHY a sibling of searchOverlay (not folded into it): same centered-modal
     * shell and selection model, but the data is the raw Spotify queue shape and
     * the action is "jump to this up-next track". Keeping it a distinct, pure
     * method means it is buffer-testable exactly like the palette, and the
     * command's loop just hands it the queue snapshot + selection + status.
     *
     * Long queues scroll: only a fixed window of rows is shown, kept around the
     * selection, so the modal never overflows the inline viewport.
     *
     * @param  list<array{name?: string, uri?: string, artists?: list<array{name?: string}>}>  $queue
     */
    public function queueOverlay(array $queue, int $selectedIndex, string $status = ''): Widget
    {
        $theme = $this->theme;

        // "track  —  artist" per row, from the raw Spotify track shape.
        $labels = array_map(
            fn (array $track): string => $this->truncateDisplay(
                ($track['name'] ?? 'Unknown').'  —  '.($track['artists'][0]['name'] ?? 'Unknown'),
                self::TEXT_WIDTH,
            ),
            array_values($queue),
        );

        $bodyLines = $labels === []
            ? [Line::fromSpans(Span::styled('Queue is empty', $theme->dim()))]
            : $this->windowedSelectableList($labels, $selectedIndex);

        // Unified footer shape, shared with the search palette and playlist picker.
        $footer = $this->modalFooter('↑↓ select · ⏎ play · esc close', $status);

        $contents = $this->listContents($bodyLines, $footer);

        return $this->centeredModal(' '.$theme->icon('queue').' Up Next ', $contents);
    }

    /**
     * Playlist picker overlay: a centered modal listing the user's playlists
     * (name + track count), ↑↓ to select, ⏎ to play, esc to cancel. An inline
     * status (e.g. a no-device note) is surfaced in the footer like the palette's.
     *
     * WHY here, same reasoning as queueOverlay/searchOverlay: pure composition over
     * the playlist snapshot + selection + status, so it is unit-testable on a
     * buffer; the command owns the key handling that turns ⏎ into a playPlaylist().
     *
     * @param  list<array{id?: string, name?: string, tracks?: array{total?: int}}>  $playlists
     */
    public function playlistOverlay(array $playlists, int $selectedIndex, string $status = ''): Widget
    {
        $theme = $this->theme;

        // "name  (N tracks)" per row, from the raw Spotify playlist shape.
        $labels = array_map(
            fn (array $playlist): string => $this->truncateDisplay(
                ($playlist['name'] ?? 'Untitled').'  ('.($playlist['tracks']['total'] ?? 0).' tracks)',
                self::TEXT_WIDTH,
            ),
            array_values($playlists),
        );

        $bodyLines = $labels === []
            ? [Line::fromSpans(Span::styled('No playlists found', $theme->dim()))]
            : $this->windowedSelectableList($labels, $selectedIndex);

        // Unified footer shape, shared with the search palette and queue overlay.
        $footer = $this->modalFooter('↑↓ select · ⏎ play · esc close', $status);

        $contents = $this->listContents($bodyLines, $footer);

        return $this->centeredModal(' '.$theme->icon('playlist').' Playlists ', $contents);
    }

    /**
     * Compose a modal footer: the static key hints, prefixed with any inline status
     * (a no-device note, a "+ queued" confirm). One helper so the search palette,
     * queue overlay and playlist picker render the status/hints identically — the
     * audit's "three overlays, three behaviours" → one consistent footer.
     */
    private function modalFooter(string $hints, string $status = ''): string
    {
        return $status === '' ? $hints : $status.'   ·   '.$hints;
    }

    /**
     * Wrap body lines + a footer hint in the standard list layout: a flexible
     * list region above a footer pinned to the bottom row. Same structure the
     * search palette uses, so a full list never pushes the footer/status out of
     * the modal (the bug the palette's Grid was introduced to fix).
     *
     * @param  list<Line>  $bodyLines
     */
    private function listContents(array $bodyLines, string $footer): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(1),    // list / empty hint
                Constraint::length(1), // footer pinned to the bottom
            )
            ->widgets(
                ParagraphWidget::fromLines(...($bodyLines === [] ? [Line::fromString('')] : $bodyLines)),
                ParagraphWidget::fromLines(Line::fromSpans(Span::styled($footer, $this->theme->dim()))),
            );
    }

    /**
     * Turn flat row labels into selectable Lines, showing only a fixed-height
     * window kept around the selection so long lists scroll instead of overflowing.
     * The selected row gets a ▶ marker AND a reversed accent style — the marker so
     * the selection survives a colour-stripped terminal, the style so it pops where
     * colour works (identical treatment to the search palette's rows).
     *
     * @param  list<string>  $labels
     * @return list<Line>
     */
    private function windowedSelectableList(array $labels, int $selectedIndex): array
    {
        $count = count($labels);

        // Window start: clamp so the selection stays visible and we never read past
        // the end. Short lists (≤ window) show in full from the top.
        $start = $count <= self::SEARCH_RESULTS
            ? 0
            : max(0, min($selectedIndex - intdiv(self::SEARCH_RESULTS, 2), $count - self::SEARCH_RESULTS));

        $lines = [];
        foreach (array_slice($labels, $start, self::SEARCH_RESULTS, true) as $i => $label) {
            $lines[] = $i === $selectedIndex
                ? Line::fromSpans(Span::styled('▶ '.$label, $this->theme->accent()->addModifier(Modifier::BOLD | Modifier::REVERSED)))
                : Line::fromSpans(Span::styled('  '.$label, $this->theme->text()));
        }

        return $lines;
    }

    /**
     * The shared centered-modal shell: a mood-framed Block (titled) floated in the
     * middle ~60% of the width, blank gutters either side. php-tui's layout engine
     * owns the centering math; the modal fills the inline viewport's height, so it
     * reads as a centered overlay over the (replaced) player surface. Reused by the
     * search palette and the queue/playlist overlays so they stay visually identical.
     */
    private function centeredModal(string $title, Widget $contents): Widget
    {
        $theme = $this->theme;

        $modal = BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($theme->borderStyle())
            ->titles(Title::fromString($title))
            ->titleStyle($theme->heading())
            ->widget($contents);

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(20),
                Constraint::percentage(60),
                Constraint::percentage(20),
            )
            ->widgets(
                ParagraphWidget::fromString(''),
                $modal,
                ParagraphWidget::fromString(''),
            );
    }

    /**
     * Progress row: a play/pause cue + "elapsed / total" as TEXT on the left, then a
     * progress bar that ALWAYS shows its full track (empty + filled).
     *
     * WHY a clearer cue than the old "="/">" : those read as cryptic punctuation. ▶
     * (play) and ‖ (pause) are universally legible. Crucially they are the
     * TEXT-presentation glyphs (U+25B6 / U+2016), NOT the emoji ▶️/⏸️ — no variation
     * selector, width-1 in both php-tui and the terminal. That matters because this
     * line repaints every second; the old VS-16 emoji had ambiguous width, so the
     * cell diff drifted and stale digits accumulated ("0:00" → "0:009/2:53"). Plain
     * width-1 glyphs keep every cell aligned, so the per-second redraw stays clean.
     */
    private function progressRow(PlayerViewModel $vm): Widget
    {
        // Width-stable, NON-emoji state cue (see WHY above): ▶ playing / ‖ paused.
        $cue = $vm->isPlaying ? '▶' : '‖';

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            // 16 cols fit "▶ 188:88 / 188:88"-class labels; then the fixed-width bar.
            ->constraints(
                Constraint::length(16),
                Constraint::length(1),
                Constraint::length(self::PROGRESS_BAR_WIDTH),
                Constraint::min(1),
            )
            ->widgets(
                ParagraphWidget::fromString($cue.' '.$vm->progressLabel())->style($this->theme->accent()),
                ParagraphWidget::fromString(''), // gutter between label and bar
                $this->progressBar($vm->progressFraction()),
                ParagraphWidget::fromString(''), // absorb the remaining width
            );
    }

    /**
     * A progress bar that renders its TRACK as well as its fill: a dim ░ run for the
     * empty portion and an accent █ run for the played portion.
     *
     * WHY hand-built instead of GaugeWidget: php-tui's Gauge paints ONLY the filled
     * cells and leaves the remainder as bare background, so at 0% there is no visible
     * bar at all — the audit's "no bar at 0%" complaint. Drawing the track ourselves
     * keeps the bar's full extent visible at every ratio (0%, mid, 100%). █ and ░ are
     * width-1 block glyphs, so the per-second redraw stays cell-aligned.
     */
    private function progressBar(float $fraction): Widget
    {
        $width = self::PROGRESS_BAR_WIDTH;
        $filled = max(0, min($width, (int) round($fraction * $width)));

        return ParagraphWidget::fromLines(Line::fromSpans(
            Span::styled(str_repeat('█', $filled), $this->theme->gaugeStyle()),
            Span::styled(str_repeat('░', $width - $filled), $this->theme->dim()),
        ));
    }

    /**
     * Volume row: speaker + percent as TEXT on the left, then a SHORT fixed-width
     * gauge — so the meter reads "🔊 89%  ▓▓▓▓▓░░" cleanly instead of a percent
     * buried in a full-width fill. Label is "n/a" when no device reports a volume.
     */
    private function volumeRow(PlayerViewModel $vm): Widget
    {
        $label = $vm->volume === null ? 'n/a' : $vm->volume.'%';

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::length(8), Constraint::length(22), Constraint::min(1))
            ->widgets(
                ParagraphWidget::fromString($this->theme->icon('volume').' '.$label)->style($this->theme->accent()),
                GaugeWidget::default()
                    ->ratio($vm->volumeFraction())
                    ->label(Span::fromString(''))
                    ->style($this->theme->volumeStyle()),
                ParagraphWidget::fromString(''), // keep the gauge short; absorb the remaining width
            );
    }

    /**
     * The key legend, as two grouped `key action` lines.
     *
     * WHY two grouped lines, not one dense strip: the audit's core "can't see the
     * keys" complaint was a single line that crammed 9 items AND mixed key-hints
     * with live state (🔀 off / 🔁 Off), so it was ambiguous which token was the
     * key, the action, or the state. The fix splits concerns three ways — transport
     * controls on line 1, panel/overlay actions on line 2, and live shuffle/repeat
     * state moved out entirely to statusRow(). Each binding reads as "<key> <action>"
     * with the key in the mood accent (so it pops) and the action dim.
     *
     * WHY no emoji here: the old strip used variation-selector glyphs (▶️🔀🔁⏭️⏮️)
     * whose terminal width is AMBIGUOUS and reads wide/ragged — the same VS-16 trap
     * documented on the progress line. Plain ASCII keys stay width-stable and align.
     *
     * @return list<Line>
     */
    private function controlsLines(): array
    {
        // Line 1: transport. Line 2: panels/overlays + quit. Grouping makes the
        // strip scannable and keeps each line inside a standard terminal width.
        return [
            $this->legendLine([
                ['space', 'play/pause'],
                ['n', 'next'],
                ['p', 'prev'],
                ['s', 'shuffle'],
                ['r', 'repeat'],
            ]),
            $this->legendLine([
                ['/', 'search'],
                ['u', 'queue'],
                ['l', 'playlists'],
                ['q', 'quit'],
            ]),
        ];
    }

    /**
     * Compose one legend line from `[key, action]` pairs: accent key + dim action,
     * separated by a dim middot for breathing room.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function legendLine(array $pairs): Line
    {
        $theme = $this->theme;
        $sep = Span::styled('  ·  ', $theme->dim());

        $spans = [];
        foreach ($pairs as $i => [$key, $action]) {
            if ($i > 0) {
                $spans[] = $sep;
            }
            $spans[] = Span::styled($key, $theme->accent());
            $spans[] = Span::styled(' '.$action, $theme->dim());
        }

        return Line::fromSpans(...$spans);
    }

    /**
     * Compact now-playing status line, sat just beneath the gauges: the active
     * device (when known) · shuffle on/off · repeat mode.
     *
     * WHY separate from the key legend: live state and key-hints are different kinds
     * of information — interleaving them (the old "🔀 off · 🔁 Off" inside the key
     * strip) was the audit's readability snag. Each segment reads as a dim label + an
     * accent/text value, WIDTH-STABLE TEXT ONLY (no emoji — this line repaints on every
     * shuffle/repeat toggle, so a mis-width glyph could leave residue): "Living Room ·
     * shuffle on · repeat All". Tight " · " separators keep it inside the narrow
     * (art-shrunk) info column; the device name is hard-truncated for the same reason
     * and trails so it clips before the shuffle/repeat state if space runs out.
     */
    private function statusRow(PlayerViewModel $vm): Widget
    {
        $theme = $this->theme;

        $spans = [
            Span::styled('shuffle ', $theme->dim()),
            Span::styled($vm->shuffle ? 'on' : 'off', $theme->accent()),
            Span::styled(' · repeat ', $theme->dim()),
            Span::styled($vm->repeatLabel(), $theme->accent()),
        ];

        if ($vm->deviceName !== null && $vm->deviceName !== '') {
            $spans[] = Span::styled(' · ', $theme->dim());
            $spans[] = Span::styled($this->truncateDisplay($vm->deviceName, 16), $theme->text());
        }

        return ParagraphWidget::fromLines(Line::fromSpans(...$spans));
    }

    /**
     * Optional one-line "up next" peek, beneath the status line — the next queued
     * track, "up next  <track — artist>". Returns null when the loop hasn't resolved
     * a next track (no queue / nothing up next / API miss), so the row is omitted
     * entirely rather than showing an empty label. Width-stable text only (same
     * per-refresh-redraw reason as the status line).
     */
    private function upNextRow(PlayerViewModel $vm): ?Widget
    {
        if ($vm->upNext === null || $vm->upNext === '') {
            return null;
        }

        $theme = $this->theme;

        return ParagraphWidget::fromLines(Line::fromSpans(
            Span::styled('up next  ', $theme->dim()),
            Span::styled($this->truncateDisplay($vm->upNext, self::TEXT_WIDTH - 8), $theme->text()),
        ));
    }
}
