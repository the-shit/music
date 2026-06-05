<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\Response;

/**
 * Circuit breaker for Spotify Web API 429s.
 *
 * WHY: the panes (`player:premium`, `watch`, `pulse`, …) poll the Web API all
 * day. When Spotify rate-limits the app it answers every call with 429 and a
 * Retry-After that can be HOURS — and every further request just digs the hole
 * deeper. This breaker persists the resume deadline next to the OAuth token
 * (rate-limit.json, so every process sees the same state) and short-circuits
 * all API calls until it passes: callers get the same graceful empties they
 * already return on failure, WITHOUT touching the network.
 *
 * Static on purpose: it is shared process-wide, has no dependencies beyond
 * config('spotify.token_path'), and the services already construct their HTTP
 * calls inline — guard() wraps a call site in one line.
 */
final class SpotifyRateLimit
{
    /** Cool-down (seconds) when a 429 arrives without a Retry-After header. */
    private const DEFAULT_RETRY_AFTER = 60;

    /**
     * Run a Spotify Web API call through the breaker: while rate-limited the
     * request is NOT sent and null is returned (callers treat it like any other
     * failed response); otherwise the response is inspected so a 429 trips the
     * breaker for every subsequent call in every process.
     *
     * @param  callable(): Response  $request
     */
    public static function guard(callable $request): ?Response
    {
        if (self::active()) {
            return null;
        }

        $response = $request();

        if ($response->status() === 429) {
            self::hit($response->header('Retry-After'));
        }

        return $response;
    }

    /**
     * Record a 429: persist the resume deadline derived from Retry-After.
     */
    public static function hit(?string $retryAfter): void
    {
        $seconds = max(1, (int) ($retryAfter ?: self::DEFAULT_RETRY_AFTER));

        $file = self::path();
        $dir = dirname($file);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, json_encode(['resumes_at' => time() + $seconds]));
    }

    /**
     * Whether the breaker is currently open (API calls must short-circuit).
     */
    public static function active(): bool
    {
        return self::resumesAt() !== null;
    }

    /**
     * The unix timestamp when Spotify lets us back in, or null when not
     * rate-limited. An expired deadline is cleaned up on read so the breaker
     * closes itself without any explicit reset step.
     */
    public static function resumesAt(): ?int
    {
        $file = self::path();

        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        $deadline = (int) (is_array($data) ? ($data['resumes_at'] ?? 0) : 0);

        if ($deadline <= time()) {
            @unlink($file);

            return null;
        }

        return $deadline;
    }

    /**
     * Forget the deadline (used by tests; production expiry is automatic).
     */
    public static function clear(): void
    {
        @unlink(self::path());
    }

    /**
     * The deadline lives alongside the OAuth token so every command/daemon
     * process shares one breaker (and it survives restarts).
     */
    private static function path(): string
    {
        return dirname((string) config('spotify.token_path')).'/rate-limit.json';
    }
}
