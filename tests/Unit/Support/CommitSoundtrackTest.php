<?php

use App\Support\CommitSoundtrack;

/**
 * Raw `git log` output in the exact format CommitSoundtrack parses
 * (COMMIT_START / hash / author / date / subject / %B / COMMIT_END).
 */
function fakeGitLog(): string
{
    $blocks = [
        // Two commits on the same track (Rick Astley), with the Now-playing line.
        ['aaaaaaa111', 'Jordan', '2026-01-05 10:00:00 -0700', 'feat: add player',
            "feat: add player\n\n🎵 Now playing: Rick Astley - Never Gonna Give You Up\n🔗 Track: https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC"],
        ['bbbbbbb222', 'Jordan', '2026-02-10 09:00:00 -0700', 'fix: player crash',
            "fix: player crash\n\n🎵 Now playing: Rick Astley - Never Gonna Give You Up\nhttps://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC"],
        // A track whose TITLE contains a dash — must survive the split.
        ['ccccccc333', 'Jordan', '2026-03-01 12:00:00 -0700', 'feat: queue editor',
            "feat: queue editor\n\n🎵 Now playing: Daft Punk - Harder, Better - Faster\nhttps://open.spotify.com/track/2ZZZZZZZZZZZZZZZZZZZZ1"],
        // A track with NO Now-playing line — name stays null (enriched later).
        ['ddddddd444', 'Jordan', '2026-03-15 12:00:00 -0700', 'docs: readme',
            "docs: readme\n\nhttps://open.spotify.com/track/3YYYYYYYYYYYYYYYYYYYY2"],
        // No track URL at all — must be skipped entirely.
        ['eeeeeee555', 'Jordan', '2026-04-01 12:00:00 -0700', 'chore: bump deps',
            "chore: bump deps\n\nnothing playing"],
    ];

    $out = '';
    foreach ($blocks as [$hash, $author, $date, $subject, $body]) {
        $out .= "COMMIT_START\n{$hash}\n{$author}\n{$date}\n{$subject}\n{$body}\nCOMMIT_END\n";
    }

    return $out;
}

it('parses only commits that carry a spotify track url', function (): void {
    $commits = (new CommitSoundtrack)->commits(fakeGitLog());

    expect($commits)->toHaveCount(4); // the no-URL chore commit is skipped
    expect(array_column($commits, 'subject'))->not->toContain('chore: bump deps');
});

it('extracts name and artist from the now-playing line', function (): void {
    $commits = (new CommitSoundtrack)->commits(fakeGitLog());

    expect($commits[0]['track_name'])->toBe('Never Gonna Give You Up');
    expect($commits[0]['track_artist'])->toBe('Rick Astley');
    expect($commits[0]['type'])->toBe('feat');
});

it('keeps a dash inside the track title when splitting artist - title', function (): void {
    $commits = (new CommitSoundtrack)->commits(fakeGitLog());
    $daft = collect($commits)->firstWhere('track_artist', 'Daft Punk');

    expect($daft['track_name'])->toBe('Harder, Better - Faster');
});

it('leaves name null when no now-playing line is present', function (): void {
    $commits = (new CommitSoundtrack)->commits(fakeGitLog());
    $docs = collect($commits)->firstWhere('subject', 'docs: readme');

    expect($docs['track_name'])->toBeNull();
});

it('groups by track, most played first', function (): void {
    $soundtrack = new CommitSoundtrack;
    $groups = $soundtrack->groupByTrack($soundtrack->commits(fakeGitLog()));

    expect($groups)->toHaveCount(3);
    expect(count($groups[0]['commits']))->toBe(2); // Rick Astley leads with 2
    expect($groups[0]['name'])->toBe('Never Gonna Give You Up');
});

it('computes wrapped stats', function (): void {
    $soundtrack = new CommitSoundtrack;
    $stats = $soundtrack->stats($soundtrack->commits(fakeGitLog()));

    expect($stats['total_commits'])->toBe(4);
    expect($stats['total_tracks'])->toBe(3);
    expect($stats['top_tracks'][0]['count'])->toBe(2);
    expect($stats['top_artist'])->toBe(['name' => 'Rick Astley', 'count' => 2]);
    // feat appears twice → dominant.
    expect($stats['dominant_type'])->toBe('feat');
    expect($stats['type_breakdown']['feat'])->toBe(2);
    expect($stats['first_date'])->toContain('2026-01-05');
    expect($stats['last_date'])->toContain('2026-03-15');
});

it('returns nothing for empty history', function (): void {
    expect((new CommitSoundtrack)->commits(''))->toBe([]);
    expect((new CommitSoundtrack)->stats([]))->toMatchArray([
        'total_commits' => 0,
        'total_tracks' => 0,
        'top_artist' => null,
        'dominant_type' => null,
    ]);
});
