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
     * own clipping kicks in. Generous so normal titles are never touched.
     */
    private const TEXT_WIDTH = 60;

    public function __construct(private readonly PlayerTheme $theme) {}

    /**
     * The full now-playing panel: a mood-framed Block containing track metadata,
     * progress + volume gauges, and a controls hint strip.
     */
    public function nowPlaying(PlayerViewModel $vm): Widget
    {
        $theme = $this->theme;

        $track = ParagraphWidget::fromString($this->truncateDisplay($vm->title, self::TEXT_WIDTH))
            ->style($theme->text()->addModifier(Modifier::BOLD));

        $artist = ParagraphWidget::fromString(
            $theme->icon('artist').' '.$this->truncateDisplay($vm->artist, self::TEXT_WIDTH)
        )->style($theme->text());

        $album = ParagraphWidget::fromString(
            $theme->icon('album').' '.$this->truncateDisplay($vm->album, self::TEXT_WIDTH)
        )->style($theme->dim());

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1),  // track title
                Constraint::length(1),  // artist
                Constraint::length(1),  // album
                Constraint::length(1),  // progress gauge
                Constraint::length(1),  // volume gauge
                Constraint::min(1),     // controls hint — absorbs any slack
            )
            ->widgets(
                $track,
                $artist,
                $album,
                $this->progressRow($vm),
                $this->volumeRow($vm),
                ParagraphWidget::fromString($this->controlsHint($vm))->style($theme->dim()),
            );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderStyle($theme->borderStyle())
            ->titles(Title::fromString(' '.$theme->icon('music').' NOW PLAYING · '.$theme->moodBadge().' '))
            ->titleStyle($theme->heading())
            ->widget($body);
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
     * Progress row: a state glyph (playing/paused) beside a real Gauge whose
     * fill ratio and "elapsed / total" label both come from the view model.
     */
    private function progressRow(PlayerViewModel $vm): Widget
    {
        $stateIcon = $vm->isPlaying ? $this->theme->icon('play') : $this->theme->icon('pause');

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::length(3), Constraint::min(1))
            ->widgets(
                ParagraphWidget::fromString($stateIcon)->style($this->theme->accent()),
                GaugeWidget::default()
                    ->ratio($vm->progressFraction())
                    ->label(Span::fromString($vm->progressLabel()))
                    ->style($this->theme->gaugeStyle()),
            );
    }

    /**
     * Volume row: speaker glyph beside a Gauge; label reads the percent (or n/a
     * when no device reports a volume).
     */
    private function volumeRow(PlayerViewModel $vm): Widget
    {
        $label = $vm->volume === null ? 'n/a' : $vm->volume.'%';

        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::length(3), Constraint::min(1))
            ->widgets(
                ParagraphWidget::fromString($this->theme->icon('volume'))->style($this->theme->accent()),
                GaugeWidget::default()
                    ->ratio($vm->volumeFraction())
                    ->label(Span::fromString($label))
                    ->style($this->theme->volumeStyle()),
            );
    }

    /**
     * Single-line key-binding hint strip, surfacing live shuffle/repeat state so
     * the controls double as a status readout.
     */
    private function controlsHint(PlayerViewModel $vm): string
    {
        $theme = $this->theme;

        return implode('   ', [
            'space '.$theme->icon('play').'/'.$theme->icon('pause'),
            'n '.$theme->icon('next'),
            'p '.$theme->icon('prev'),
            $theme->icon('shuffle').' '.($vm->shuffle ? 'on' : 'off'),
            $theme->icon('repeat').' '.$vm->repeatLabel(),
            '/ '.$theme->icon('search'),
            'q quit',
        ]);
    }
}
