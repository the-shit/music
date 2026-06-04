<?php

declare(strict_types=1);

namespace App\Player;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches lyrics for a track from lrclib.net — a free, no-auth lyrics API.
 *
 * WHY a standalone lane (mirroring {@see AlbumArtRenderer}'s shape): Spotify's
 * Web API does not expose lyrics at all, so the player needs a second remote
 * source — which means network I/O, an external contract, and failure modes
 * that must NEVER reach the draw loop. Keeping the fetch behind this single
 * lines() seam makes it Http::fake-testable and lets the command treat lyrics
 * exactly like album art: ask once, render whatever comes back, degrade to a
 * calm empty state on any miss.
 *
 * Contract: lines() returns the plain (unsynced) lyrics as a list of display
 * lines, or null for ANY failure — no match, network error, blank metadata,
 * instrumental track. Callers branch on null, never on exceptions.
 */
final class LyricsProvider
{
    /** lrclib's exact-match lookup: artist + track (+ optional duration). */
    private const ENDPOINT = 'https://lrclib.net/api/get';

    /**
     * Short timeout: lyrics are cosmetic and fetched from inside the player's
     * interactive loop — a slow third-party API must stall the UI briefly at
     * worst, never hang it.
     */
    private const TIMEOUT_SECONDS = 4;

    /**
     * Per-track result cache. Values: the lyric lines (success) or null (no
     * match / failure). array_key_exists distinguishes "never tried" from
     * "tried and missed" so reopening the overlay on the same track never
     * refetches — the same convention as AlbumArtRenderer's decode cache.
     *
     * @var array<string, list<string>|null>
     */
    private static array $cache = [];

    /**
     * The lyrics for a track as displayable lines, or null when unavailable.
     *
     * Duration (seconds) is optional but sharpens lrclib's exact-match lookup
     * when known; pass null when the player has no duration.
     *
     * @return list<string>|null
     */
    public function lines(string $artist, string $title, ?int $durationSeconds = null): ?array
    {
        // Nothing to look up — don't burn a request (or a cache slot) on it.
        if (trim($artist) === '' || trim($title) === '') {
            return null;
        }

        $key = mb_strtolower(trim($artist).'|'.trim($title).'|'.($durationSeconds ?? ''));

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        return self::$cache[$key] = $this->fetch($artist, $title, $durationSeconds);
    }

    /**
     * Hit lrclib and normalise the response into display lines.
     *
     * Only plainLyrics is consumed: synced (LRC-timestamped) highlighting is a
     * possible future nicety, but plain scrollable lines are the contract the
     * overlay renders today.
     *
     * @return list<string>|null
     */
    private function fetch(string $artist, string $title, ?int $durationSeconds): ?array
    {
        try {
            $query = ['artist_name' => $artist, 'track_name' => $title];

            if ($durationSeconds !== null && $durationSeconds > 0) {
                $query['duration'] = $durationSeconds;
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)->get(self::ENDPOINT, $query);

            if (! $response->successful()) {
                return null; // 404 = no match; any other non-2xx is equally "no lyrics"
            }

            $plain = $response->json('plainLyrics');
        } catch (Throwable) {
            // DNS, TLS, connection, timeout — all collapse to "no lyrics".
            return null;
        }

        // Instrumental tracks come back with empty/null plainLyrics.
        if (! is_string($plain) || trim($plain) === '') {
            return null;
        }

        // Split on any newline convention; keep interior blank lines (stanza
        // breaks) but trim the trailing whitespace lrclib sometimes pads with.
        $lines = preg_split('/\R/u', trim($plain)) ?: [];

        return array_values(array_map(rtrim(...), $lines));
    }
}
