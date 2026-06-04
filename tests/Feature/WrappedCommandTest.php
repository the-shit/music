<?php

use App\Commands\WrappedCommand;
use App\Support\CommitSoundtrack;

function wrappedStats(array $overrides = []): array
{
    return array_merge([
        'total_commits' => 137,
        'total_tracks' => 53,
        'top_tracks' => [
            ['track_id' => 'a', 'name' => 'Never Gonna Give You Up', 'artist' => 'Rick Astley', 'count' => 26],
            ['track_id' => 'b', 'name' => 'Blinding Lights', 'artist' => 'The Weeknd', 'count' => 11],
            ['track_id' => 'c', 'name' => 'Bohemian Rhapsody', 'artist' => 'Queen', 'count' => 8],
        ],
        'top_artist' => ['name' => 'Rick Astley', 'count' => 31],
        'type_breakdown' => ['feat' => 54, 'fix' => 30],
        'dominant_type' => 'feat',
        'first_date' => '2026-01-01 00:00:00 -0700',
        'last_date' => '2026-06-01 00:00:00 -0700',
    ], $overrides);
}

it('renders a card whose borders all line up', function (): void {
    $lines = $this->app->make(WrappedCommand::class)->buildCard(wrappedStats());

    // Every line — top border, side rows, bottom border — must be the same
    // display width, or the box looks broken in the terminal.
    $widths = array_map(fn (string $l): int => mb_strwidth($l, 'UTF-8'), $lines);
    expect(array_unique($widths))->toHaveCount(1);
});

it('shows the headline stats and top tracks on the card', function (): void {
    $card = implode("\n", $this->app->make(WrappedCommand::class)->buildCard(wrappedStats()));

    expect($card)
        ->toContain('WRAPPED')
        ->toContain('137')
        ->toContain('53')
        ->toContain('Never Gonna Give You Up')
        ->toContain('Rick Astley')
        ->toContain('shipping'); // feat → 🚀 shipping
});

it('truncates an over-long track name but keeps the box aligned', function (): void {
    $stats = wrappedStats([
        'top_tracks' => [
            ['track_id' => 'a', 'name' => str_repeat('Supercalifragilistic ', 6), 'artist' => 'X', 'count' => 9],
        ],
    ]);
    $lines = $this->app->make(WrappedCommand::class)->buildCard($stats);
    $card = implode("\n", $lines);

    expect($card)->toContain('…');
    expect(array_unique(array_map(fn (string $l): int => mb_strwidth($l, 'UTF-8'), $lines)))->toHaveCount(1);
});

it('falls back to the track id when a name is unknown', function (): void {
    $stats = wrappedStats([
        'top_tracks' => [['track_id' => '4uLU6hMCjMI7', 'name' => null, 'artist' => null, 'count' => 3]],
    ]);
    $card = implode("\n", $this->app->make(WrappedCommand::class)->buildCard($stats));

    expect($card)->toContain('4uLU6hMCjMI7');
});

it('outputs raw stats as json with --json', function (): void {
    $fake = Mockery::mock(CommitSoundtrack::class)->makePartial();
    $fake->shouldReceive('commits')->andReturn([
        ['hash' => 'a1', 'short' => 'a1', 'author' => 'J', 'date' => '2026-01-01 00:00:00 -0700',
            'subject' => 'feat: x', 'type' => 'feat', 'track_id' => 'T1', 'track_url' => 'u',
            'track_name' => 'Song One', 'track_artist' => 'Artist'],
        ['hash' => 'b2', 'short' => 'b2', 'author' => 'J', 'date' => '2026-02-01 00:00:00 -0700',
            'subject' => 'fix: y', 'type' => 'fix', 'track_id' => 'T1', 'track_url' => 'u',
            'track_name' => 'Song One', 'track_artist' => 'Artist'],
    ]);
    $this->app->instance(CommitSoundtrack::class, $fake);

    $code = Illuminate\Support\Facades\Artisan::call('wrapped', ['--json' => true, '--no-enrich' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($code)->toBe(0);
    expect($output)
        ->toContain('"total_commits": 2')
        ->toContain('"total_tracks": 1');
});

it('handles a repo with no soundtracked commits', function (): void {
    $fake = Mockery::mock(CommitSoundtrack::class)->makePartial();
    $fake->shouldReceive('commits')->andReturn([]);
    $this->app->instance(CommitSoundtrack::class, $fake);

    $this->artisan('wrapped')
        ->expectsOutputToContain('No commits with Spotify track URLs')
        ->assertExitCode(0);
});
