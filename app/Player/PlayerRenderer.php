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

        // The track title is the hero line: bold AND mood-accent coloured so it
        // clearly outranks the white artist and dim album beneath it.
        $track = ParagraphWidget::fromString($this->truncateDisplay($vm->title, self::TEXT_WIDTH))
            ->style($theme->accent()->addModifier(Modifier::BOLD));

        $artist = ParagraphWidget::fromString(
            $theme->icon('artist').' '.$this->truncateDisplay($vm->artist, self::TEXT_WIDTH)
        )->style($theme->text());

        $album = ParagraphWidget::fromString(
            $theme->icon('album').' '.$this->truncateDisplay($vm->album, self::TEXT_WIDTH)
        )->style($theme->dim());

        // The key legend is static (no live state, no VS-16 emoji) so it stays
        // readable and width-stable; live shuffle/repeat state lives in statusRow()
        // near the gauges instead — see controlsLines()/statusRow().
        $controls = ParagraphWidget::fromLines(...$this->controlsLines());

        // LAYOUT IS CONDITIONAL ON REAL ART. Album art is an accent, not scaffolding:
        // when there is a genuine, decodable cover we split into two columns; when
        // there is NOT (no/empty url, or a fetch/decode failure) we render the clean
        // single-column panel instead of a dead placeholder block. Asking hasArt()
        // first also primes the decode cache that render() reuses — one fetch, not two.
        $body = $this->art->hasArt($vm->albumArtUrl)
            ? $this->twoColumnBody($vm, $track, $artist, $album, $controls)
            : $this->singleColumnBody($vm, $track, $artist, $album, $controls);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($theme->borderStyle())
            ->titles(Title::fromString($this->nowPlayingTitle()))
            ->titleStyle($theme->heading())
            ->widget($body);
    }

    /**
     * The clean single-column now-playing body — metadata, gauges, and the controls
     * strip stacked full-width. The default when there is no album art.
     */
    private function singleColumnBody(PlayerViewModel $vm, Widget $track, Widget $artist, Widget $album, Widget $controls): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1),  // track title
                Constraint::length(1),  // artist
                Constraint::length(1),  // album
                Constraint::length(1),  // progress
                Constraint::length(1),  // volume
                Constraint::length(1),  // shuffle/repeat status line, beneath the gauges
                Constraint::min(1),     // flexible spacer → breathing room above controls
                Constraint::length(2),  // two-line key legend, pinned to the bottom
            )
            ->widgets(
                $track,
                $artist,
                $album,
                $this->progressRow($vm),
                $this->volumeRow($vm),
                $this->statusRow($vm),
                ParagraphWidget::fromString(''),
                $controls,
            );
    }

    /**
     * The two-column body — a modest album-art accent on the LEFT, the now-playing
     * info on the RIGHT, and the controls strip spanning FULL width beneath both
     * (it is long and would clip in the narrow info column). Only used when hasArt().
     */
    private function twoColumnBody(PlayerViewModel $vm, Widget $track, Widget $artist, Widget $album, Widget $controls): Widget
    {
        $info = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1),  // track title
                Constraint::length(1),  // artist
                Constraint::length(1),  // album
                Constraint::length(1),  // progress
                Constraint::length(1),  // volume
                Constraint::length(1),  // shuffle/repeat status line, beneath the gauges
                Constraint::min(1),     // flexible spacer → fills the rest of the column
            )
            ->widgets(
                $track,
                $artist,
                $album,
                $this->progressRow($vm),
                $this->volumeRow($vm),
                $this->statusRow($vm),
                ParagraphWidget::fromString(''),
            );

        // The art renderer rescales to whatever area the layout grants it, so the
        // fixed ART_COLS slice is all the sizing it needs. hasArt() already proved
        // the cover decodes, so render() here returns real art (cache hit), never
        // the placeholder.
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
                $info,
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
            // One row per result. The selected row gets a ▶ marker AND a reversed
            // accent style — the marker so the selection survives a colour-stripped
            // or non-truecolor terminal, the style so it pops where colour works.
            foreach (array_slice($results, 0, self::SEARCH_RESULTS) as $i => $track) {
                $label = $this->truncateDisplay(
                    ($track['name'] ?? 'Unknown').'  —  '.($track['artist'] ?? 'Unknown'),
                    self::TEXT_WIDTH,
                );

                $bodyLines[] = $i === $selectedIndex
                    ? Line::fromSpans(Span::styled('▶ '.$label, $theme->accent()->addModifier(Modifier::BOLD | Modifier::REVERSED)))
                    : Line::fromSpans(Span::styled('  '.$label, $theme->text()));
            }
        }

        // Footer: static key hints, plus any inline status (e.g. a no-device note
        // or a "+ queued" confirm). Two actions on the selected result now — ⏎ plays
        // it now, `a` adds it to the queue.
        $footer = '↑↓ select · ⏎ play · a queue · esc cancel';
        if ($status !== '') {
            $footer = $status.'   ·   '.$footer;
        }

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

        // Footer mirrors the palette/picker: static hints, prefixed with any status.
        $footer = '↑↓ select · ⏎ play · esc close';
        if ($status !== '') {
            $footer = $status.'   ·   '.$footer;
        }

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

        // Footer mirrors the palette: static hints, prefixed with any inline status.
        $footer = '↑↓ select · ⏎ play · esc cancel';
        if ($status !== '') {
            $footer = $status.'   ·   '.$footer;
        }

        $contents = $this->listContents($bodyLines, $footer);

        return $this->centeredModal(' '.$theme->icon('playlist').' Playlists ', $contents);
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
     * Progress row: state glyph + "elapsed / total" rendered as TEXT on the left,
     * then a clean full-width Gauge whose fill = progressFraction().
     *
     * WHY the label is text, not the gauge's own label: GaugeRenderer paints the
     * label INSIDE the bar and the block fill bleeds through the gap, mangling
     * "1:51 / 4:53" into garbage like "4 53". Keeping the time outside the bar
     * makes it always legible; the gauge passes an empty label so it draws a pure
     * fill. Ratio comes straight from the (tested) view model so bar and clock agree.
     *
     * WHY no leading ▶️/⏸️ glyph here: those are emoji + a variation selector, an
     * AMBIGUOUS-width sequence. On the once-per-second progress line the terminal's
     * width accounting drifts, so php-tui's cell diff fails to overwrite the old
     * elapsed time and stale digits accumulate ("0:00" → "0:009/2:53"). Dropping the
     * VS-16 emoji from this fast-updating line keeps every cell fixed-width, so the
     * diff repaints cleanly. Play/pause state still reads from the gauge motion and
     * the controls strip. A plain ASCII "▸"/"⏸"-free marker keeps a state cue with
     * stable width.
     */
    private function progressRow(PlayerViewModel $vm): Widget
    {
        // ASCII-only, width-stable state cue (no variation selectors): see WHY above.
        $stateCue = $vm->isPlaying ? '>' : '=';

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            // 16 cols fit "> 188:88 / 188:88"-class labels without clipping; gauge takes the rest.
            ->constraints(Constraint::length(16), Constraint::length(1), Constraint::min(1))
            ->widgets(
                ParagraphWidget::fromString($stateCue.' '.$vm->progressLabel())->style($this->theme->accent()),
                ParagraphWidget::fromString(''), // gutter between label and bar
                GaugeWidget::default()
                    ->ratio($vm->progressFraction())
                    ->label(Span::fromString('')) // empty → clean fill, no buried text
                    ->style($this->theme->gaugeStyle()),
            );
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
     * Compact shuffle/repeat status line, sat just beneath the gauges.
     *
     * WHY separate from the key legend: live state and key-hints are different
     * kinds of information — interleaving them (the old "🔀 off · 🔁 Off" inside the
     * key strip) was the audit's readability snag. Here each mode reads as a dim
     * label + an accent value ("shuffle on · repeat All"), width-stable text only,
     * so it scans as a status readout rather than another binding.
     */
    private function statusRow(PlayerViewModel $vm): Widget
    {
        $theme = $this->theme;

        return ParagraphWidget::fromLines(Line::fromSpans(
            Span::styled('shuffle ', $theme->dim()),
            Span::styled($vm->shuffle ? 'on' : 'off', $theme->accent()),
            Span::styled('   ·   repeat ', $theme->dim()),
            Span::styled($vm->repeatLabel(), $theme->accent()),
        ));
    }
}
