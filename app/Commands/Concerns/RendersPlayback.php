<?php

namespace App\Commands\Concerns;

use PhpTui\Tui\Color\AnsiColor;

/**
 * Reusable terminal-rendering helpers for playback displays.
 *
 * Extracted from PlayerCommand so observability commands (pulse, marquee)
 * can share the exact same progress bar, volume meter, and mode glyphs.
 */
trait RendersPlayback
{
    /**
     * Classify a "mood" from a track's audio features (energy/valence), mapping
     * onto the same vocabulary as config('autopilot.mood_presets'). Returns
     * 'neutral' when features are unavailable (e.g. the audio-features endpoint
     * is deprecated/403 for the app) so theming degrades gracefully.
     *
     * @param  array{energy?: float, valence?: float, instrumentalness?: float}|null  $features
     */
    protected function classifyMood(?array $features): string
    {
        if ($features === null || ! isset($features['energy'], $features['valence'])) {
            return 'neutral';
        }

        $energy = (float) $features['energy'];
        $valence = (float) $features['valence'];
        $instrumental = (float) ($features['instrumentalness'] ?? 0);

        return match (true) {
            $energy >= 0.85 && $valence >= 0.7 => 'party',
            $energy >= 0.75 => 'hype',
            $energy <= 0.4 && $valence <= 0.35 => 'melancholy',
            $energy <= 0.4 => 'chill',
            $energy <= 0.6 && $instrumental >= 0.5 => 'focus',
            $valence >= 0.7 => 'upbeat',
            default => 'flow',
        };
    }

    /**
     * Accent colour for a mood — the ambient tint applied across the player
     * surface (border, title, progress fill).
     */
    protected function moodColor(string $mood): AnsiColor
    {
        return match ($mood) {
            'chill' => AnsiColor::Cyan,
            'flow' => AnsiColor::Green,
            'focus' => AnsiColor::Blue,
            'hype' => AnsiColor::Red,
            'party' => AnsiColor::Magenta,
            'upbeat' => AnsiColor::LightYellow,
            'melancholy' => AnsiColor::LightBlue,
            'ambient' => AnsiColor::Gray,
            'workout' => AnsiColor::LightRed,
            'sleep' => AnsiColor::Blue,
            default => AnsiColor::Cyan,
        };
    }

    /**
     * Icon + name badge shown in the now-playing title, e.g. "😌 chill".
     */
    protected function moodLabel(string $mood): string
    {
        $icon = match ($mood) {
            'chill' => '😌',
            'flow' => '🌊',
            'focus' => '🎯',
            'hype' => '🔥',
            'party' => '🎉',
            'upbeat' => '☀️',
            'melancholy' => '🌧️',
            'ambient' => '🌌',
            'workout' => '💪',
            'sleep' => '🌙',
            default => '🎵',
        };

        return $mood === 'neutral' ? $icon : $icon.' '.$mood;
    }

    /**
     * Truncate to a display width (mb_strwidth-based), appending an ellipsis
     * when clipped. Used for track/artist/album lines.
     */
    protected function truncateDisplay(string $text, int $width): string
    {
        if (mb_strwidth($text) <= $width) {
            return $text;
        }

        $ellipsis = '…';
        $target = max(0, $width - 1);
        while (mb_strwidth($text) > $target && $text !== '') {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).$ellipsis;
    }

    protected function formatProgress(int $progressMs, int $durationMs): string
    {
        $progressSec = floor($progressMs / 1000);
        $durationSec = floor($durationMs / 1000);

        $progressMin = floor($progressSec / 60);
        $progressSec %= 60;

        $durationMin = floor($durationSec / 60);
        $durationSec %= 60;

        $percentage = $durationMs > 0 ? ($progressMs / $durationMs) : 0;
        $barLength = 30;
        $filled = floor($percentage * $barLength);

        $bar = str_repeat('━', (int) $filled).'●'.str_repeat('━', (int) ($barLength - $filled - 1));

        return sprintf(
            '%s %s %d:%02d/%d:%02d',
            $progressMs < $durationMs ? '▶️' : '⏸️',
            $bar,
            $progressMin, $progressSec,
            $durationMin, $durationSec
        );
    }

    protected function formatVolume(int $volume): string
    {
        $icon = match (true) {
            $volume === 0 => '🔇',
            $volume <= 33 => '🔈',
            $volume <= 66 => '🔉',
            default => '🔊'
        };

        $barLength = 20;
        $filled = floor($volume * $barLength / 100);
        $bar = str_repeat('▓', (int) $filled).str_repeat('░', (int) ($barLength - $filled));

        return sprintf('%s %s %d%%', $icon, $bar, $volume);
    }

    protected function formatPlaybackModes(bool $shuffle, string $repeat): string
    {
        $modes = [];

        if ($shuffle) {
            $modes[] = '🔀 Shuffle';
        }

        if ($repeat !== 'off') {
            $repeatIcon = $repeat === 'track' ? '🔂' : '🔁';
            $repeatText = $repeat === 'track' ? 'Repeat Track' : 'Repeat All';
            $modes[] = $repeatIcon.' '.$repeatText;
        }

        return $modes !== [] ? implode('  ', $modes) : '';
    }

    /**
     * Terminal display width of a string. mb_strwidth() reports the columns a
     * monospace terminal actually uses: East-Asian-Wide emoji (🎵 👤 💿 🔊) = 2,
     * and ambiguous glyphs incl. variation-selector symbols (▶️ ⏸️) = 1, which
     * matches how these terminals render them (the U+FE0F itself is zero-width).
     * Emoji width is ultimately terminal/font-dependent; this is the common case.
     */
    protected function displayWidth(string $text): int
    {
        return mb_strwidth($text);
    }

    /**
     * Right-pad to a target display width (not byte/char count), so box borders
     * line up regardless of emoji. The inverse of sprintf('%-Ns'), which pads by
     * bytes and ruins alignment for multibyte/emoji content.
     */
    protected function padRight(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - $this->displayWidth($text)));
    }

    protected function clearScreen(): void
    {
        // Clear screen for better display (works on Unix-like systems)
        if (PHP_OS_FAMILY !== 'Windows') {
            system('clear');
        }
    }
}
