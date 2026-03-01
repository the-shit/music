<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');

    $this->service = new SpotifyService;

    $mockAuth = Mockery::mock(SpotifyAuthManager::class)->makePartial();
    $mockAuth->shouldReceive('getAccessToken')->andReturn('valid_token');
    $mockAuth->shouldReceive('requireAuth')->andReturn(null);

    $reflection = new ReflectionClass($this->service);
    foreach (['auth', 'player', 'discovery'] as $prop) {
        $r = $reflection->getProperty($prop);
        $r->setAccessible(true);
        $obj = $r->getValue($this->service);
        if ($prop === 'auth') {
            $r->setValue($this->service, $mockAuth);
        } else {
            $sub = new ReflectionClass($obj);
            $subAuth = $sub->getProperty('auth');
            $subAuth->setAccessible(true);
            $subAuth->setValue($obj, $mockAuth);
        }
    }
});

describe('SpotifyService', function () {

    describe('Playback Control', function () {

        it('searches for tracks', function () {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => [
                        'items' => [[
                            'uri' => 'spotify:track:123',
                            'name' => 'Test Song',
                            'artists' => [['name' => 'Test Artist']],
                            'album' => ['name' => 'Test Album'],
                        ]],
                    ],
                ]),
            ]);

            $result = $this->service->search('test');

            expect($result)->toHaveKeys(['uri', 'name', 'artist']);
            expect($result['name'])->toBe('Test Song');
            expect($result['artist'])->toBe('Test Artist');
        });

        it('searches multiple tracks', function () {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => [
                        'items' => [
                            [
                                'uri' => 'spotify:track:1',
                                'name' => 'Song 1',
                                'artists' => [['name' => 'Artist 1']],
                                'album' => ['name' => 'Album 1'],
                            ],
                            [
                                'uri' => 'spotify:track:2',
                                'name' => 'Song 2',
                                'artists' => [['name' => 'Artist 2']],
                                'album' => ['name' => 'Album 2'],
                            ],
                        ],
                    ],
                ]),
            ]);

            $results = $this->service->searchMultiple('test', 'track', 10);

            expect($results)->toHaveCount(2);
            expect($results[0]['name'])->toBe('Song 1');
            expect($results[1]['name'])->toBe('Song 2');
        });

        it('gets current playback state', function () {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'item' => [
                        'name' => 'Current Song',
                        'artists' => [['name' => 'Current Artist']],
                        'album' => ['name' => 'Current Album'],
                        'duration_ms' => 180000,
                    ],
                    'progress_ms' => 90000,
                    'is_playing' => true,
                    'device' => [
                        'id' => 'device123',
                        'name' => 'Test Device',
                        'volume_percent' => 50,
                    ],
                ]),
            ]);

            $current = $this->service->getCurrentPlayback();

            expect($current)->toHaveKeys(['name', 'artist', 'album', 'device']);
            expect($current['name'])->toBe('Current Song');
            expect($current['device']['volume_percent'])->toBe(50);
        });

        it('controls volume', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            $result = $this->service->setVolume(42);

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=42');
            });
        });

        it('handles volume boundaries', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            $this->service->setVolume(150);
            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=100');
            });

            $this->service->setVolume(-10);
            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=0');
            });
        });
    });

    describe('Device Management', function () {

        it('gets available devices', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        [
                            'id' => 'device1',
                            'name' => 'MacBook',
                            'type' => 'Computer',
                            'is_active' => true,
                            'volume_percent' => 70,
                        ],
                        [
                            'id' => 'device2',
                            'name' => 'iPhone',
                            'type' => 'Smartphone',
                            'is_active' => false,
                            'volume_percent' => 50,
                        ],
                    ],
                ]),
            ]);

            $devices = $this->service->getDevices();

            expect($devices)->toHaveCount(2);
            expect($devices[0]['name'])->toBe('MacBook');
            expect($devices[0]['is_active'])->toBeTrue();
        });

        it('finds active device', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        [
                            'id' => 'inactive',
                            'is_active' => false,
                        ],
                        [
                            'id' => 'active',
                            'is_active' => true,
                            'name' => 'Active Device',
                        ],
                    ],
                ]),
            ]);

            $device = $this->service->getActiveDevice();

            expect($device['id'])->toBe('active');
            expect($device['is_active'])->toBeTrue();
        });
    });

    describe('Queue Management', function () {

        it('gets queue', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/queue' => Http::response([
                    'currently_playing' => [
                        'name' => 'Current Track',
                    ],
                    'queue' => [
                        ['name' => 'Next Track'],
                        ['name' => 'Track After That'],
                    ],
                ]),
            ]);

            $queue = $this->service->getQueue();

            expect($queue)->toHaveKeys(['currently_playing', 'queue']);
            expect($queue['queue'])->toHaveCount(2);
            expect($queue['queue'][0]['name'])->toBe('Next Track');
        });

        it('adds to queue', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/queue*' => Http::response([], 204),
            ]);

            $this->service->addToQueue('spotify:track:123');

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'uri=spotify%3Atrack%3A123');
            });
        });
    });

    describe('Playlist Management', function () {

        it('gets user playlists', function () {
            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([
                    'items' => [
                        [
                            'id' => 'playlist1',
                            'name' => 'My Playlist',
                            'tracks' => ['total' => 50],
                            'owner' => ['display_name' => 'Me'],
                        ],
                    ],
                ]),
            ]);

            $playlists = $this->service->getPlaylists();

            expect($playlists)->toHaveCount(1);
            expect($playlists[0]['name'])->toBe('My Playlist');
        });

        it('plays playlist', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $result = $this->service->playPlaylist('playlist123');

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT') {
                    return false;
                }
                $body = json_decode($request->body(), true);

                return isset($body['context_uri']) && $body['context_uri'] === 'spotify:playlist:playlist123';
            });
        });
    });
});
