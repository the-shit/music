<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Response;
use Throwable;

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
 * The token directory may be READ-ONLY (e.g. bind-mounted into a container).
 * Recording a 429 must never crash the command, so writes fall back to a
 * writable state dir (SPOTIFY_CLI_STATE_DIR, else $XDG_STATE_HOME/spotify-cli,
 * else the system temp dir) and finally to an in-process deadline. Reads take
 * the latest deadline from every location.
 *
 * Static on purpose: it is shared process-wide, has no dependencies beyond
 * config('spotify.token_path'), and the services already construct their HTTP
 * calls inline — guard() wraps a call site in one line.
 */
final class SpotifyRateLimit
{
    /** Cool-down (seconds) when a 429 arrives without a Retry-After header. */
    private const DEFAULT_RETRY_AFTER = 60;

    private const FILE = 'rate-limit.json';

    /** Deadline recorded in this process (survives an unwritable marker). */
    private static ?int $memory = null;

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
     * Never throws — a read-only config dir must not turn a 429 into a crash.
     */
    public static function hit(?string $retryAfter): void
    {
        $seconds = max(1, (int) ($retryAfter ?: self::DEFAULT_RETRY_AFTER));
        $deadline = time() + $seconds;

        self::$memory = max(self::$memory ?? 0, $deadline);

        $payload = (string) json_encode(['resumes_at' => $deadline]);

        foreach (self::paths() as $file) {
            if (self::write($file, $payload)) {
                return;
            }
        }
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
        $now = time();
        $latest = null;

        if (self::$memory !== null) {
            if (self::$memory > $now) {
                $latest = self::$memory;
            } else {
                self::$memory = null;
            }
        }

        foreach (self::paths() as $file) {
            $deadline = self::read($file);

            if ($deadline === null) {
                continue;
            }

            if ($deadline <= $now) {
                @unlink($file);

                continue;
            }

            $latest = max($latest ?? 0, $deadline);
        }

        return $latest;
    }

    /**
     * Human-readable reason when the breaker is open, or null when it is not.
     *
     * WHY: callers turn a short-circuited request into a graceful empty (no
     * devices, no playback). Without this the user sees "No devices found"
     * while the real problem is a 429 with a Retry-After that can be hours.
     */
    public static function describe(): ?string
    {
        $resumesAt = self::resumesAt();

        if ($resumesAt === null) {
            return null;
        }

        $minutes = max(1, (int) ceil(($resumesAt - time()) / 60));

        return sprintf(
            'Spotify is rate-limiting requests (HTTP 429); try again after %s (about %d min).',
            self::formatTime($resumesAt),
            $minutes,
        );
    }

    /**
     * Forget the deadline (used by tests; production expiry is automatic).
     */
    public static function clear(): void
    {
        self::$memory = null;

        foreach (self::paths() as $file) {
            @unlink($file);
        }
    }

    /**
     * Marker locations, primary first. The primary lives alongside the OAuth
     * token so every command/daemon process shares one breaker (and it
     * survives restarts); the rest are writable fallbacks.
     *
     * @return list<string>
     */
    private static function paths(): array
    {
        $paths = [dirname((string) config('spotify.token_path')).'/'.self::FILE];

        $stateDir = config('spotify.state_dir');

        if (is_string($stateDir) && trim($stateDir) !== '') {
            $paths[] = rtrim(trim($stateDir), '/').'/'.self::FILE;
        } else {
            $xdg = getenv('XDG_STATE_HOME');

            if (is_string($xdg) && trim($xdg) !== '') {
                $paths[] = rtrim(trim($xdg), '/').'/spotify-cli/'.self::FILE;
            }

            $user = function_exists('posix_getuid') ? (string) posix_getuid() : get_current_user();
            $paths[] = rtrim(sys_get_temp_dir(), '/').'/spotify-cli-'.$user.'/'.self::FILE;
        }

        return array_values(array_unique($paths));
    }

    private static function read(string $file): ?int
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if (! is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        $deadline = (int) (is_array($data) ? ($data['resumes_at'] ?? 0) : 0);

        return $deadline > 0 ? $deadline : null;
    }

    private static function write(string $file, string $payload): bool
    {
        try {
            $dir = dirname($file);

            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                return false;
            }

            return @file_put_contents($file, $payload) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Local wall-clock time for the deadline, honouring $TZ when set.
     */
    private static function formatTime(int $timestamp): string
    {
        $zone = null;
        $tz = getenv('TZ');

        if (is_string($tz) && $tz !== '') {
            try {
                $zone = new DateTimeZone(ltrim($tz, ':'));
            } catch (Throwable) {
                $zone = null;
            }
        }

        $zone ??= new DateTimeZone(date_default_timezone_get());

        return (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone)->format('g:i A T');
    }
}
