<?php

use App\Services\SpotifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Mock config values
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');

    // Create temp token file
    $this->tokenFile = sys_get_temp_dir().'/spotify_test_token.json';
    $this->service = new SpotifyService;

    // Use reflection to set the token file path
    $reflection = new ReflectionClass($this->service);
    $property = $reflection->getProperty('tokenFile');
    $property->setAccessible(true);
    $property->setValue($this->service, $this->tokenFile);
});

afterEach(function () {
    // Clean up temp file
    if (file_exists($this->tokenFile)) {
        unlink($this->tokenFile);
    }
});

describe('SpotifyService', function () {

    describe('Authentication', function () {

        test('checks if configured correctly', function () {
            // Clean up the token file first
            if (file_exists($this->tokenFile)) {
                unlink($this->tokenFile);
            }

            // Create a new service
            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);

            // Set the token file path to our test path
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            // Clear any existing token data
            $tokenProp = $reflection->getProperty('accessToken');
            $tokenProp->setAccessible(true);
            $tokenProp->setValue($service, null);

            // Now service should not be configured (no access token)
            expect($service->isConfigured())->toBeFalse();

            // Add token
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'test_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            // Force reload
            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            expect($service->isConfigured())->toBeTrue();
        });

        test('refreshes expired token', function () {
            // Set expired token
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() - 100, // Expired
            ]));

            // Mock refresh response
            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'access_token' => 'new_token',
                    'expires_in' => 3600,
                ]),
            ]);

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            $method = $reflection->getMethod('ensureValidToken');
            $method->setAccessible(true);
            $method->invoke($service);

            // Check token was refreshed
            $tokenData = json_decode(file_get_contents($this->tokenFile), true);
            expect($tokenData['access_token'])->toBe('new_token');
        });
    });

    describe('Playback Control', function () {

        beforeEach(function () {
            // Set valid token
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

        test('searches for tracks', function () {
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

        test('searches multiple tracks', function () {
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

        test('gets current playback state', function () {
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

        test('controls volume', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            $result = $this->service->setVolume(42);

            expect($result)->toBeTrue();

            // Verify the request was made with correct params
            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=42');
            });
        });

        test('handles volume boundaries', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            // Test upper boundary
            $this->service->setVolume(150);
            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=100');
            });

            // Test lower boundary
            $this->service->setVolume(-10);
            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=0');
            });
        });
    });

    describe('Device Management', function () {

        beforeEach(function () {
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

        test('gets available devices', function () {
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

        test('finds active device', function () {
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

        beforeEach(function () {
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

        test('gets queue', function () {
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

        test('adds to queue', function () {
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

        beforeEach(function () {
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

        test('gets user playlists', function () {
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

        test('plays playlist', function () {
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

    describe('OAuth Scope Management', function () {

        test('parses scopes from token data', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
                'scope' => 'user-read-playback-state user-modify-playback-state streaming',
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            $scopes = $service->getGrantedScopes();

            expect($scopes)->toBeArray();
            expect($scopes)->toContain('user-read-playback-state');
            expect($scopes)->toContain('user-modify-playback-state');
            expect($scopes)->toContain('streaming');
            expect($scopes)->toHaveCount(3);
        });

        test('returns empty array when no scopes in token', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            $scopes = $service->getGrantedScopes();

            expect($scopes)->toBeArray();
            expect($scopes)->toBeEmpty();
        });

        test('hasScope returns true for granted scope', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
                'scope' => 'user-read-playback-state user-modify-playback-state',
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            expect($service->hasScope('user-read-playback-state'))->toBeTrue();
            expect($service->hasScope('user-modify-playback-state'))->toBeTrue();
        });

        test('hasScope returns false for non-granted scope', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
                'scope' => 'user-read-playback-state',
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            expect($service->hasScope('user-modify-playback-state'))->toBeFalse();
            expect($service->hasScope('streaming'))->toBeFalse();
        });

        test('setGrantedScopes sets scopes correctly', function () {
            $service = new SpotifyService;

            $service->setGrantedScopes(['scope1', 'scope2', 'scope3']);

            expect($service->getGrantedScopes())->toBe(['scope1', 'scope2', 'scope3']);
            expect($service->hasScope('scope1'))->toBeTrue();
            expect($service->hasScope('scope2'))->toBeTrue();
            expect($service->hasScope('scope3'))->toBeTrue();
            expect($service->hasScope('scope4'))->toBeFalse();
        });

        test('saves scopes when saving token data', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
                'scope' => 'user-read-playback-state user-modify-playback-state',
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            // Trigger saveTokenData by calling a method that saves
            $saveMethod = $reflection->getMethod('saveTokenData');
            $saveMethod->setAccessible(true);
            $saveMethod->invoke($service);

            // Read the saved file
            $savedData = json_decode(file_get_contents($this->tokenFile), true);

            expect($savedData)->toHaveKey('scope');
            expect($savedData['scope'])->toBe('user-read-playback-state user-modify-playback-state');
        });

        test('handles empty scope string gracefully', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
                'scope' => '',
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            expect($service->getGrantedScopes())->toBeEmpty();
        });

        test('handles scopes with extra whitespace', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
                'scope' => '  user-read-playback-state   user-modify-playback-state  ',
            ]));

            $service = new SpotifyService;
            $reflection = new ReflectionClass($service);
            $property = $reflection->getProperty('tokenFile');
            $property->setAccessible(true);
            $property->setValue($service, $this->tokenFile);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            $scopes = $service->getGrantedScopes();

            expect($scopes)->toContain('user-read-playback-state');
            expect($scopes)->toContain('user-modify-playback-state');
        });
    });
});
