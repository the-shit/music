<?php

declare(strict_types=1);

use App\Player\GenreMoodMap;

/**
 * WHY: GenreMoodMap is the player's mood source now that /audio-features 403s.
 * These tests pin the genre→mood vocabulary (chill/flow/focus/hype/party/upbeat/
 * melancholy/ambient/workout/sleep) used by RendersPlayback, the case-insensitive
 * substring matching against real Spotify genre strings, the first-confident-match
 * behaviour over an artist's genre array, and the all-important 'neutral' fallback.
 */
beforeEach(function (): void {
    $this->map = new GenreMoodMap;
});

it('maps representative genres to the expected mood', function (string $genre, string $mood): void {
    expect($this->map->moodForGenre($genre))->toBe($mood);
})->with([
    'ambient' => ['ambient', 'ambient'],
    'drone → ambient' => ['drone', 'ambient'],
    'metal → hype' => ['heavy metal', 'hype'],
    'punk → hype' => ['punk rock', 'hype'],
    'hardcore → hype' => ['hardcore', 'hype'],
    'edm → party' => ['edm', 'party'],
    'dance → party' => ['dance pop', 'party'],
    'deep house → party' => ['deep house', 'party'],
    'sad → melancholy' => ['sad', 'melancholy'],
    'slow → melancholy' => ['slowcore', 'melancholy'],
    'lo-fi → focus' => ['lo-fi beats', 'focus'],
    'study → focus' => ['study beats', 'focus'],
    'classical → chill' => ['classical', 'chill'],
    'jazz → chill' => ['smooth jazz', 'chill'],
    'workout → workout' => ['workout', 'workout'],
    'sleep → sleep' => ['sleep', 'sleep'],
    'pop → upbeat' => ['pop', 'upbeat'],
    'hip hop → flow' => ['hip hop', 'flow'],
]);

it('matches genres case-insensitively', function (): void {
    expect($this->map->moodForGenre('HEAVY METAL'))->toBe('hype')
        ->and($this->map->moodForGenre('Deep House'))->toBe('party');
});

it('falls back to neutral for an unknown genre', function (): void {
    expect($this->map->moodForGenre('polka'))->toBe('neutral')
        ->and($this->map->moodForGenre(''))->toBe('neutral')
        ->and($this->map->moodForGenre('   '))->toBe('neutral');
});

it('resolves the first confident match from an artist genre array', function (): void {
    // "rock" alone is not a rule; "punk rock" is the first confident match → hype.
    expect($this->map->resolveMood(['rock', 'punk rock', 'pop']))->toBe('hype');
});

it('resolves neutral when no genre matches any rule', function (): void {
    expect($this->map->resolveMood(['polka', 'yodeling']))->toBe('neutral')
        ->and($this->map->resolveMood([]))->toBe('neutral');
});

it('only ever returns moods in the RendersPlayback vocabulary', function (): void {
    $vocabulary = ['chill', 'flow', 'focus', 'hype', 'party', 'upbeat', 'melancholy', 'ambient', 'workout', 'sleep', 'neutral'];

    foreach (['ambient', 'metal', 'edm', 'sad', 'lo-fi', 'classical', 'workout', 'sleep', 'pop', 'hip hop', 'polka', ''] as $genre) {
        expect($vocabulary)->toContain($this->map->moodForGenre($genre));
    }
});
