<?php

declare(strict_types=1);

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * WHY: getArtistGenres() is the mood signal for the premium player (the old
 * /audio-features source 403s). It hits the NON-deprecated GET /artists/{id} and
 * must degrade quietly — an empty array, never an exception — so a failed lookup
 * just yields the 'neutral' mood instead of crashing the live render loop. These
 * tests mock Http to pin the happy path and every soft-fail branch.
 */
beforeEach(function (): void {
    $mockAuth = Mockery::mock(SpotifyAuthManager::class)->makePartial();
    $mockAuth->shouldReceive('getAccessToken')->andReturn('valid_token');
    $mockAuth->shouldReceive('requireAuth')->andReturn(null);

    $this->service = new SpotifyPlayerService($mockAuth);
});

it('returns the artist genres from GET /artists/{id}', function (): void {
    Http::fake([
        'api.spotify.com/v1/artists/*' => Http::response([
            'id' => 'artist123',
            'name' => 'Queen',
            'genres' => ['classic rock', 'glam rock'],
        ]),
    ]);

    expect($this->service->getArtistGenres('artist123'))->toBe(['classic rock', 'glam rock']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'artists/artist123'));
});

it('returns an empty array when the artist has no genres key', function (): void {
    Http::fake([
        'api.spotify.com/v1/artists/*' => Http::response(['id' => 'artist123', 'name' => 'Mystery']),
    ]);

    expect($this->service->getArtistGenres('artist123'))->toBe([]);
});

it('returns an empty array on a failed request without throwing', function (): void {
    Http::fake([
        'api.spotify.com/v1/artists/*' => Http::response(null, 403),
    ]);

    expect($this->service->getArtistGenres('artist123'))->toBe([]);
});

it('returns an empty array for a blank artist id without calling the API', function (): void {
    Http::fake();

    expect($this->service->getArtistGenres(''))->toBe([]);

    Http::assertNothingSent();
});
