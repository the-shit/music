<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SpotifyDiscoveryService;

final class MoodSearch
{
    /**
     * Search each query and keep tracks that belong to the mood.
     *
     * Over-fetches per query so keyword-OR junk can be dropped without
     * undershooting the requested count.
     *
     * @param  array<int, string>  $queries
     * @return array<int, array<string, mixed>>
     */
    public static function gather(SpotifyDiscoveryService $discovery, array $queries, int $needed, string $mood): array
    {
        $tracks = [];
        $seen = [];
        $queryCount = count($queries);

        if ($queryCount === 0 || $needed <= 0) {
            return [];
        }

        foreach ($queries as $query) {
            if (count($tracks) >= $needed) {
                break;
            }

            $perQuery = (int) ceil($needed / $queryCount) + 5;
            $results = $discovery->searchMultiple($query, 'track', $perQuery);

            foreach ($results as $track) {
                $uri = $track['uri'] ?? null;
                if (! is_string($uri) || isset($seen[$uri])) {
                    continue;
                }
                if (! self::allows($mood, $track)) {
                    continue;
                }
                $seen[$uri] = true;
                $tracks[] = $track;
            }
        }

        return array_slice($tracks, 0, $needed);
    }

    /**
     * @param  array<string, mixed>  $track
     */
    public static function allows(string $mood, array $track): bool
    {
        if ($mood !== 'flow') {
            return true;
        }

        return ! self::isFlowContaminant($track);
    }

    /**
     * Keyword-OR search for "focus" / "ambient" / "coding" returns viral
     * hits that are not flow music. Drop them by name + artist.
     *
     * @param  array<string, mixed>  $track
     */
    public static function isFlowContaminant(array $track): bool
    {
        $name = self::normalize((string) ($track['name'] ?? ''));
        $artist = self::normalize((string) ($track['artist'] ?? ''));

        if ($name === 'pest' && str_contains($artist, 'kwite')) {
            return true;
        }

        if (str_contains($name, 'boom boom pow')) {
            return true;
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $stripped = preg_replace('/\([^)]*\)/', ' ', $value);
        $value = is_string($stripped) ? $stripped : $value;
        $collapsed = preg_replace('/\s+/', ' ', $value);

        return trim(is_string($collapsed) ? $collapsed : $value);
    }
}
