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

        $controls = ParagraphWidget::fromString($this->controlsHint($vm))->style($theme->dim());

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
                Constraint::min(1),     // flexible spacer → breathing room above controls
                Constraint::length(1),  // controls hint, pinned to the bottom
            )
            ->widgets(
                $track,
                $artist,
                $album,
                $this->progressRow($vm),
                $this->volumeRow($vm),
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
                Constraint::min(1),     // flexible spacer → fills the rest of the column
            )
            ->widgets(
                $track,
                $artist,
                $album,
                $this->progressRow($vm),
                $this->volumeRow($vm),
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
                Constraint::length(1),  // controls hint, pinned full-width to the bottom
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
     * Single-line key-binding hint strip, surfacing live shuffle/repeat state so
     * the controls double as a status readout.
     */
    private function controlsHint(PlayerViewModel $vm): string
    {
        $theme = $this->theme;

        // Middot separators give the strip breathing room and clear grouping.
        return implode('   ·   ', [
            $theme->icon('play').'/'.$theme->icon('pause').' space',
            $theme->icon('next').' n',
            $theme->icon('prev').' p',
            $theme->icon('shuffle').' '.($vm->shuffle ? 'on' : 'off'),
            $theme->icon('repeat').' '.$vm->repeatLabel(),
            $theme->icon('search').' /',
            'quit q',
        ]);
    }
}
