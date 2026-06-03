<?php

namespace App\Commands\Concerns;

/**
 * Reusable terminal-rendering helpers for playback displays.
 *
 * Extracted from PlayerCommand so observability commands (pulse, marquee)
 * can share the exact same progress bar, volume meter, and mode glyphs.
 */
trait RendersPlayback
{
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
