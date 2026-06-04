<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * The single source of truth for "which song was playing when this code was
 * committed". Reads git history, keeps only commits that carry a Spotify track
 * URL (the Vibe Check gate guarantees most do), and exposes them grouped by
 * track plus a few wrapped-style aggregates.
 *
 * Deliberately network-free: parsing and stats work entirely off the commit
 * messages, so this class is fully unit-testable by injecting raw `git log`
 * output. Track names are best-effort from the "🎵 Now playing: Artist - Title"
 * line commits already include; callers that want guaranteed names enrich the
 * track ids via Spotify oEmbed/API afterwards.
 */
class CommitSoundtrack
{
    /**
     * The git-log format used for parsing. Each commit is delimited so a body
     * spanning multiple lines (where the track URL usually lives) survives.
     */
    private const FORMAT = 'COMMIT_START%n%H%n%an%n%ai%n%s%n%B%nCOMMIT_END';

    /**
     * Parse every commit that carries a Spotify track URL.
     *
     * @param  string|null  $rawLog  Inject `git log` output (tests); null shells out.
     * @return list<array{hash:string,short:string,author:string,date:string,subject:string,type:?string,track_id:string,track_url:string,track_name:?string,track_artist:?string}>
     */
    public function commits(?string $rawLog = null): array
    {
        $output = $rawLog ?? $this->gitLog();
        if ($output === '') {
            return [];
        }

        preg_match_all('/COMMIT_START\n(.+?)\nCOMMIT_END/s', $output, $matches);

        $commits = [];
        foreach ($matches[1] as $block) {
            $lines = explode("\n", $block, 5);
            if (count($lines) < 5) {
                continue;
            }

            [$hash, $author, $date, $subject, $body] = $lines;
            $fullMessage = $subject."\n".$body;

            if (! preg_match('#https://open\.spotify\.com/track/([A-Za-z0-9]+)#', $fullMessage, $urlMatch)) {
                continue;
            }

            [$name, $artist] = $this->nowPlaying($fullMessage);

            $commits[] = [
                'hash' => trim($hash),
                'short' => substr(trim($hash), 0, 7),
                'author' => trim($author),
                'date' => trim($date),
                'subject' => trim($subject),
                'type' => $this->conventionalType(trim($subject)),
                'track_id' => $urlMatch[1],
                'track_url' => $urlMatch[0],
                'track_name' => $name,
                'track_artist' => $artist,
            ];
        }

        return $commits;
    }

    /**
     * Group commits by track, most-played first. Each group carries the first
     * resolved name/artist seen for that track.
     *
     * @param  list<array<string,mixed>>  $commits
     * @return list<array{track_id:string,track_url:string,name:?string,artist:?string,commits:list<array<string,mixed>>}>
     */
    public function groupByTrack(array $commits): array
    {
        $groups = [];

        foreach ($commits as $commit) {
            $id = $commit['track_id'];
            if (! isset($groups[$id])) {
                $groups[$id] = [
                    'track_id' => $id,
                    'track_url' => $commit['track_url'],
                    'name' => $commit['track_name'],
                    'artist' => $commit['track_artist'],
                    'commits' => [],
                ];
            }

            // Backfill name/artist from any commit that has it.
            $groups[$id]['name'] ??= $commit['track_name'];
            $groups[$id]['artist'] ??= $commit['track_artist'];
            $groups[$id]['commits'][] = $commit;
        }

        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b): int => count($b['commits']) <=> count($a['commits']));

        return $groups;
    }

    /**
     * Wrapped-style aggregates over the parsed commits.
     *
     * @param  list<array<string,mixed>>  $commits
     * @return array{total_commits:int,total_tracks:int,top_tracks:list<array{track_id:string,name:?string,artist:?string,count:int}>,top_artist:?array{name:string,count:int},type_breakdown:array<string,int>,dominant_type:?string,first_date:?string,last_date:?string}
     */
    public function stats(array $commits): array
    {
        $groups = $this->groupByTrack($commits);

        $topTracks = [];
        foreach (array_slice($groups, 0, 5) as $group) {
            $topTracks[] = [
                'track_id' => $group['track_id'],
                'name' => $group['name'],
                'artist' => $group['artist'],
                'count' => count($group['commits']),
            ];
        }

        // Top artist (only counts commits where the artist is known).
        $artistCounts = [];
        foreach ($commits as $commit) {
            $artist = $commit['track_artist'];
            if ($artist !== null && $artist !== '') {
                $artistCounts[$artist] = ($artistCounts[$artist] ?? 0) + 1;
            }
        }
        arsort($artistCounts);
        $topArtist = $artistCounts === []
            ? null
            : ['name' => (string) array_key_first($artistCounts), 'count' => reset($artistCounts)];

        // Conventional-commit type breakdown, most frequent first.
        $typeBreakdown = [];
        foreach ($commits as $commit) {
            $type = $commit['type'] ?? 'other';
            $typeBreakdown[$type] = ($typeBreakdown[$type] ?? 0) + 1;
        }
        arsort($typeBreakdown);

        $dates = array_filter(array_column($commits, 'date'));
        sort($dates);

        return [
            'total_commits' => count($commits),
            'total_tracks' => count($groups),
            'top_tracks' => $topTracks,
            'top_artist' => $topArtist,
            'type_breakdown' => $typeBreakdown,
            'dominant_type' => $typeBreakdown === [] ? null : (string) array_key_first($typeBreakdown),
            'first_date' => $dates === [] ? null : reset($dates),
            'last_date' => $dates === [] ? null : end($dates),
        ];
    }

    /**
     * Pull "Artist - Title" out of the "🎵 Now playing:" line commits embed.
     * Splits on the first " - " so titles containing a dash survive.
     *
     * @return array{0:?string,1:?string} [name, artist]
     */
    private function nowPlaying(string $message): array
    {
        if (! preg_match('/Now playing:\s*(.+)/u', $message, $m)) {
            return [null, null];
        }

        $line = trim($m[1]);
        $parts = explode(' - ', $line, 2);
        if (count($parts) === 2) {
            return [trim($parts[1]), trim($parts[0])];
        }

        return [$line, null];
    }

    /**
     * The conventional-commit type prefix (feat, fix, …) or null.
     */
    private function conventionalType(string $subject): ?string
    {
        if (preg_match('/^(feat|fix|test|ci|refactor|docs|chore|style|perf|build)(?:\([^)]+\))?!?:/', $subject, $m)) {
            return $m[1];
        }

        return null;
    }

    private function gitLog(): string
    {
        $result = Process::run('git log --all --no-merges --format="'.self::FORMAT.'"');

        return $result->successful() ? $result->output() : '';
    }
}
