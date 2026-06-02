<?php

declare(strict_types=1);

namespace App\Player;

/**
 * Immutable view model for the premium terminal player.
 *
 * WHY: This is the testability backbone of the player rebuild. It is the ONLY
 * place that knows the shape of SpotifyPlayerService::getCurrentPlayback().
 * Everything downstream (PlayerTheme, PlayerRenderer, the php-tui loop) codes
 * against this fixed, pure-PHP contract — so all the fiddly mapping and
 * div-by-zero/null guards live here under unit tests, and the rendering lanes
 * never touch raw API arrays. No php-tui, no Laravel, no I/O: just data in,
 * presentation-ready values out.
 */
final class PlayerViewModel
{
    public function __construct(
        public string $title,
        public string $artist,
        public string $album,
        public bool $isPlaying,
        public int $progressMs,
        public int $durationMs,
        public ?int $volume,
        public bool $shuffle,
        // Spotify repeat semantics: off | track | context.
        public string $repeat,
        public ?string $deviceName,
        // False when nothing is playing / no active session — drives the empty state.
        public bool $hasPlayback,
    ) {}

    /**
     * Map a SpotifyPlayerService::getCurrentPlayback() payload into a view model.
     *
     * WHY: getCurrentPlayback() returns null when there is no token and an array
     * shaped like the keys below when a track is loaded. We treat null OR an empty
     * array as "no playback" so the UI can render a calm empty panel instead of
     * blank/garbage fields. Volume and device name live on the nested `device`
     * object (Spotify's shape), which may be absent — hence the null-safe reads.
     */
    public static function fromPlayback(?array $current): self
    {
        // No session at all → empty view model with hasPlayback=false.
        if ($current === null || $current === []) {
            return new self(
                title: '',
                artist: '',
                album: '',
                isPlaying: false,
                progressMs: 0,
                durationMs: 0,
                volume: null,
                shuffle: false,
                repeat: 'off',
                deviceName: null,
                hasPlayback: false,
            );
        }

        // Volume/device name come from the nested device object when present.
        $device = $current['device'] ?? null;
        $volume = is_array($device) && isset($device['volume_percent'])
            ? (int) $device['volume_percent']
            : null;
        $deviceName = is_array($device) && isset($device['name'])
            ? (string) $device['name']
            : null;

        return new self(
            title: (string) ($current['name'] ?? $current['track'] ?? 'Unknown'),
            artist: (string) ($current['artist'] ?? 'Unknown'),
            album: (string) ($current['album'] ?? 'Unknown'),
            isPlaying: (bool) ($current['is_playing'] ?? false),
            progressMs: (int) ($current['progress_ms'] ?? 0),
            durationMs: (int) ($current['duration_ms'] ?? 0),
            volume: $volume,
            shuffle: (bool) ($current['shuffle_state'] ?? false),
            repeat: (string) ($current['repeat_state'] ?? 'off'),
            deviceName: $deviceName,
            hasPlayback: true,
        );
    }

    /**
     * Track progress as a 0..1 fraction for the progress gauge.
     *
     * WHY: durationMs can be 0 (no track / live stream) — guard against
     * division by zero, and clamp so a progressMs that briefly overshoots
     * duration never produces a gauge above 100%.
     */
    public function progressFraction(): float
    {
        if ($this->durationMs <= 0) {
            return 0.0;
        }

        return $this->clampFraction($this->progressMs / $this->durationMs);
    }

    /**
     * Human-readable "elapsed / total" label, e.g. "1:23 / 4:56".
     */
    public function progressLabel(): string
    {
        return $this->formatTime($this->progressMs).' / '.$this->formatTime($this->durationMs);
    }

    /**
     * Volume as a 0..1 fraction for the volume gauge.
     *
     * WHY: volume is null when no device reports one — treat as 0 so the gauge
     * renders empty rather than blowing up.
     */
    public function volumeFraction(): float
    {
        if ($this->volume === null) {
            return 0.0;
        }

        return $this->clampFraction($this->volume / 100);
    }

    /**
     * Friendly label for the repeat mode shown in the controls strip.
     *
     * WHY: Spotify's API value "context" means "repeat the whole album/playlist",
     * which reads better as "All" to a user. Unknown values fall back to "Off".
     */
    public function repeatLabel(): string
    {
        return match ($this->repeat) {
            'track' => 'Track',
            'context' => 'All',
            default => 'Off',
        };
    }

    /**
     * Clamp a raw ratio into the inclusive 0..1 range used by gauges.
     */
    private function clampFraction(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * Format a millisecond duration as m:ss (e.g. 83000 => "1:23").
     *
     * WHY: negative or zero values must render as "0:00", and seconds are always
     * zero-padded to two digits so labels stay aligned in the fixed-width UI.
     */
    private function formatTime(int $ms): string
    {
        $totalSeconds = (int) max(0, intdiv($ms, 1000));
        $minutes = intdiv($totalSeconds, 60);
        $seconds = $totalSeconds % 60;

        return $minutes.':'.str_pad((string) $seconds, 2, '0', STR_PAD_LEFT);
    }
}
