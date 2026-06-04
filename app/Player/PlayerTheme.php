<?php

declare(strict_types=1);

namespace App\Player;

use App\Commands\Concerns\RendersPlayback;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;

/**
 * The premium player's single source of design tokens — colours, text styles,
 * and glyphs — expressed as real php-tui {@see Style}/{@see AnsiColor} objects.
 *
 * WHY: Every rendering lane (PlayerRenderer, the php-tui loop) pulls its visual
 * language from here instead of hard-coding colours and emoji inline. Centralising
 * the palette keeps the whole surface cohesive and makes a theme change a one-file
 * edit. There is deliberately NO rendering or layout logic in this class — it only
 * answers "what does X look like", never "where does X go".
 *
 * Mood-aware: the accent is derived from the current listening mood, so the entire
 * surface (border, heading, progress gauge) tints to the music — cool for chill,
 * hot for hype. The mood→colour mapping is NOT duplicated here; we reuse the
 * already-tested {@see RendersPlayback::moodColor()} so the player and the older
 * observability commands stay in visual lockstep.
 */
final class PlayerTheme
{
    // Reused purely for moodColor()/moodLabel(); this class adds no rendering logic.
    use RendersPlayback;

    /**
     * @param  string  $mood  One of config('autopilot.mood_presets') keys, or
     *                        'neutral' when the mood is unknown (graceful default).
     */
    public function __construct(private readonly string $mood = 'neutral') {}

    /**
     * Build a theme for a given mood. Expressive factory so call sites read as
     * PlayerTheme::forMood($vm->mood) rather than a bare constructor.
     */
    public static function forMood(string $mood = 'neutral'): self
    {
        return new self($mood);
    }

    /**
     * The mood this theme is tinted for.
     */
    public function mood(): string
    {
        return $this->mood;
    }

    /**
     * Raw accent colour for callers that need a {@see AnsiColor} rather than a
     * full {@see Style} (e.g. widgets that colour their own glyphs).
     */
    public function accentColor(): AnsiColor
    {
        // Reuse the committed mood→colour mapping — never re-implement it here.
        return $this->moodColor($this->mood);
    }

    /**
     * Human badge for the mood, e.g. "😌 chill", for the now-playing heading.
     */
    public function moodBadge(): string
    {
        return $this->moodLabel($this->mood);
    }

    /**
     * Accent style — the mood hue. For highlights, active glyphs, the playhead.
     */
    public function accent(): Style
    {
        return Style::default()->fg($this->accentColor());
    }

    /**
     * Heading style — bold accent. For the panel title ("NOW PLAYING · 😌 chill").
     */
    public function heading(): Style
    {
        return Style::default()->fg($this->accentColor())->addModifier(Modifier::BOLD);
    }

    /**
     * Primary text — the track title and any content that must read first.
     */
    public function text(): Style
    {
        return Style::default()->fg(AnsiColor::White);
    }

    /**
     * Secondary text — artist/album, control hints, anything that should recede.
     */
    public function dim(): Style
    {
        return Style::default()->fg(AnsiColor::DarkGray);
    }

    /**
     * Fill style for the progress gauge — the dominant accent meter.
     */
    public function gaugeStyle(): Style
    {
        return Style::default()->fg($this->accentColor());
    }

    /**
     * Fill style for the volume gauge. Same mood hue as the progress gauge for a
     * cohesive surface; kept as its own token so a future lane can differentiate
     * the two meters without hunting through render code.
     */
    public function volumeStyle(): Style
    {
        return Style::default()->fg($this->accentColor());
    }

    /**
     * Border style for the surrounding Block — the ambient mood frame.
     */
    public function borderStyle(): Style
    {
        return Style::default()->fg($this->accentColor());
    }

    /**
     * Resolve a semantic glyph by key, so renderers reference icon('play')
     * rather than scattering emoji literals. Unknown keys yield an empty string
     * so a typo degrades to blank space rather than a crash.
     */
    public function icon(string $key): string
    {
        return match ($key) {
            'play' => '▶️',
            'pause' => '⏸️',
            'next' => '⏭️',
            'prev' => '⏮️',
            'shuffle' => '🔀',
            'repeat' => '🔁',
            'repeatOne' => '🔂',
            'volume' => '🔊',
            'search' => '🔍',
            'queue' => '📋',
            'playlist' => '📚',
            'lyrics' => '📝',
            'device' => '📱',
            'music' => '🎵',
            'artist' => '👤',
            'album' => '💿',
            default => '',
        };
    }
}
