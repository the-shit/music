<?php

use App\Services\SpotifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');

    $this->tokenFile = sys_get_temp_dir().'/spotify_discovery_test_token.json';

    file_put_contents($this->tokenFile, json_encode([
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'expires_at' => time() + 3600,
    ]));

    $this->service = new SpotifyService;
    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('tokenFile');
    $property->setAccessible(true);
    $property->setValue($this->service, $this->tokenFile);

    $method = $reflection->getMethod('loadTokenData');
    $method->setAccessible(true);
    $method->invoke($this->service);
});

afterEach(function () {
    if (file_exists($this->tokenFile)) {
        unlink($this->tokenFile);
    }
});

describe('Discovery Methods', function () {

    it('passes mood audio features to recommendations endpoint', function () {
        Http::fake([
            'api.spotify.com/v1/recommendations*' => Http::response([
                'tracks' => [[
                    'id' => 'rec1',
                    'uri' => 'spotify:track:rec1',
                    'name' => 'Mood Track',
                    'artists' => [['name' => 'Mood Artist']],
                    'album' => ['name' => 'Mood Album'],
                ]],
            ]),
        ]);

        $tracks = $this->service->getRecommendations(
            ['seed_track_1'],
            ['seed_artist_1'],
            12,
            ['target_energy' => 0.3, 'target_valence' => 0.5, 'target_tempo' => 90]
        );

        expect($tracks)->toHaveCount(1);
        expect($tracks[0]['name'])->toBe('Mood Track');

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, '/recommendations?')
                && str_contains($url, 'seed_tracks=seed_track_1')
                && str_contains($url, 'seed_artists=seed_artist_1')
                && str_contains($url, 'limit=12')
                && str_contains($url, 'target_energy=0.3')
                && str_contains($url, 'target_valence=0.5')
                && str_contains($url, 'target_tempo=90');
        });
    });

    it('requests recommendations without audio features by default', function () {
        Http::fake([
            'api.spotify.com/v1/recommendations*' => Http::response([
                'tracks' => [],
            ]),
            'api.spotify.com/v1/me/top/tracks*' => Http::response(['items' => []]),
            'api.spotify.com/v1/me/top/artists*' => Http::response(['items' => []]),
            'api.spotify.com/v1/me/playlists*' => Http::response(['items' => []]),
        ]);

        $this->service->getRecommendations(['seed_track_1'], [], 10);

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, '/recommendations?')
                && ! str_contains($url, 'target_energy=')
                && ! str_contains($url, 'target_valence=')
                && ! str_contains($url, 'target_tempo=');
        });
    });

    it('gets top tracks', function () {
        Http::fake([
            'api.spotify.com/v1/me/top/tracks*' => Http::response([
                'items' => [
                    [
                        'uri' => 'spotify:track:1',
                        'name' => 'Top Song',
                        'artists' => [['name' => 'Top Artist']],
                        'album' => ['name' => 'Top Album'],
                    ],
                    [
                        'uri' => 'spotify:track:2',
                        'name' => 'Second Song',
                        'artists' => [['name' => 'Another Artist']],
                        'album' => ['name' => 'Another Album'],
                    ],
                ],
            ]),
        ]);

        $tracks = $this->service->getTopTracks('medium_term', 20);

        expect($tracks)->toHaveCount(2);
        expect($tracks[0])->toHaveKeys(['uri', 'name', 'artist', 'album']);
        expect($tracks[0]['name'])->toBe('Top Song');
        expect($tracks[0]['artist'])->toBe('Top Artist');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'me/top/tracks')
                && str_contains($request->url(), 'time_range=medium_term');
        });
    });

    it('gets top tracks with short_term range', function () {
        Http::fake([
            'api.spotify.com/v1/me/top/tracks*' => Http::response(['items' => []]),
        ]);

        $this->service->getTopTracks('short_term', 5);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'time_range=short_term')
                && str_contains($request->url(), 'limit=5');
        });
    });

    it('gets top artists', function () {
        Http::fake([
            'api.spotify.com/v1/me/top/artists*' => Http::response([
                'items' => [
                    [
                        'name' => 'Favorite Artist',
                        'genres' => ['rock', 'alternative'],
                        'uri' => 'spotify:artist:1',
                    ],
                ],
            ]),
        ]);

        $artists = $this->service->getTopArtists();

        expect($artists)->toHaveCount(1);
        expect($artists[0])->toHaveKeys(['name', 'genres', 'uri']);
        expect($artists[0]['name'])->toBe('Favorite Artist');
        expect($artists[0]['genres'])->toBe(['rock', 'alternative']);
    });

    it('gets recently played tracks', function () {
        Http::fake([
            'api.spotify.com/v1/me/player/recently-played*' => Http::response([
                'items' => [
                    [
                        'track' => [
                            'uri' => 'spotify:track:recent1',
                            'name' => 'Recent Song',
                            'artists' => [['name' => 'Recent Artist']],
                            'album' => ['name' => 'Recent Album'],
                        ],
                        'played_at' => '2025-01-01T12:00:00Z',
                    ],
                ],
            ]),
        ]);

        $tracks = $this->service->getRecentlyPlayed(10);

        expect($tracks)->toHaveCount(1);
        expect($tracks[0])->toHaveKeys(['uri', 'name', 'artist', 'album', 'played_at']);
        expect($tracks[0]['name'])->toBe('Recent Song');
        expect($tracks[0]['played_at'])->toBe('2025-01-01T12:00:00Z');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'recently-played')
                && str_contains($request->url(), 'limit=10');
        });
    });

    it('searches by genre', function () {
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'uri' => 'spotify:track:genre1',
                            'name' => 'Genre Track',
                            'artists' => [['name' => 'Genre Artist']],
                            'album' => ['name' => 'Genre Album'],
                        ],
                    ],
                ],
            ]),
        ]);

        $tracks = $this->service->searchByGenre('electronic', 'ambient', 5);

        expect($tracks)->toHaveCount(1);
        expect($tracks[0]['name'])->toBe('Genre Track');

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, 'genre:electronic')
                && str_contains($url, 'ambient');
        });
    });

    it('searches by genre without mood', function () {
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response([
                'tracks' => ['items' => []],
            ]),
        ]);

        $this->service->searchByGenre('jazz');

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_contains($url, 'genre:jazz');
        });
    });

    it('returns empty array when top tracks API fails', function () {
        Http::fake([
            'api.spotify.com/v1/me/top/tracks*' => Http::response([], 500),
        ]);

        $tracks = $this->service->getTopTracks();

        expect($tracks)->toBe([]);
    });

    it('returns empty array when recently played API fails', function () {
        Http::fake([
            'api.spotify.com/v1/me/player/recently-played*' => Http::response([], 500),
        ]);

        $tracks = $this->service->getRecentlyPlayed();

        expect($tracks)->toBe([]);
    });
});
