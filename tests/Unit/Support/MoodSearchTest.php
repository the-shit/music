<?php

declare(strict_types=1);

use App\Services\SpotifyDiscoveryService;
use App\Support\MoodSearch;

it('rejects Boom Boom Pow and pest by Kwite for flow', function (): void {
    expect(MoodSearch::allows('flow', [
        'name' => 'Boom Boom Pow',
        'artist' => 'Black Eyed Peas',
    ]))->toBeFalse()
        ->and(MoodSearch::allows('flow', [
            'name' => 'pest',
            'artist' => 'Kwite',
        ]))->toBeFalse()
        ->and(MoodSearch::allows('flow', [
            'name' => 'Ambient Instrumentals',
            'artist' => 'Focus Ensemble',
        ]))->toBeTrue();
});

it('does not apply the flow denylist to chill', function (): void {
    expect(MoodSearch::allows('chill', [
        'name' => 'Boom Boom Pow',
        'artist' => 'Black Eyed Peas',
    ]))->toBeTrue();
});

it('drops flow contaminants while gathering', function (): void {
    $discovery = Mockery::mock(SpotifyDiscoveryService::class);
    $discovery->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:bbb', 'name' => 'Boom Boom Pow', 'artist' => 'Black Eyed Peas'],
        ['uri' => 'spotify:track:pest', 'name' => 'pest', 'artist' => 'Kwite'],
        ['uri' => 'spotify:track:good', 'name' => 'Ambient Instrumentals', 'artist' => 'Focus Ensemble'],
    ]);

    $tracks = MoodSearch::gather($discovery, ['genre:ambient instrumental'], 10, 'flow');

    expect($tracks)->toHaveCount(1)
        ->and($tracks[0]['uri'])->toBe('spotify:track:good')
        ->and(array_column($tracks, 'name'))->not->toContain('Boom Boom Pow')
        ->and(array_column($tracks, 'name'))->not->toContain('pest');
});
