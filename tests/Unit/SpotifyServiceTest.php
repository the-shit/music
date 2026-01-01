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

    $this->tokenFile = sys_get_temp_dir().'/spotify_test_token_'.uniqid().'.json';
    Config::set('spotify.token_path', $this->tokenFile);
});

afterEach(function () {
    if (file_exists($this->tokenFile)) {
        unlink($this->tokenFile);
    }
});

/**
 * Helper function to create a SpotifyService with a valid token
 */
function createAuthenticatedService(string $tokenFile): SpotifyService
{
    file_put_contents($tokenFile, json_encode([
        'access_token' => 'valid_token',
        'refresh_token' => 'refresh_token',
        'expires_at' => time() + 3600,
    ]));

    return new SpotifyService;
}

/**
 * Helper function to create SpotifyService without token
 */
function createUnauthenticatedService(string $tokenFile): SpotifyService
{
    if (file_exists($tokenFile)) {
        unlink($tokenFile);
    }

    return new SpotifyService;
}

describe('SpotifyService', function () {

    describe('configuration', function () {

        it('returns false when not configured without client id', function () {
            Config::set('spotify.client_id', '');
            Config::set('spotify.client_secret', 'secret');

            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_at' => time() + 3600,
            ]));

            $service = new SpotifyService;

            expect($service->isConfigured())->toBeFalse();
        });

        it('returns false when not configured without client secret', function () {
            Config::set('spotify.client_id', 'client_id');
            Config::set('spotify.client_secret', '');

            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_at' => time() + 3600,
            ]));

            $service = new SpotifyService;

            expect($service->isConfigured())->toBeFalse();
        });

        it('returns false when not configured without access token', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            expect($service->isConfigured())->toBeFalse();
        });

        it('returns true when fully configured', function () {
            $service = createAuthenticatedService($this->tokenFile);

            expect($service->isConfigured())->toBeTrue();
        });
    });

    describe('token management', function () {

        it('loads token data from file', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'my_access_token',
                'refresh_token' => 'my_refresh_token',
                'expires_at' => time() + 3600,
            ]));

            $service = new SpotifyService;

            expect($service->isConfigured())->toBeTrue();
        });

        it('handles missing token file gracefully', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            expect($service->isConfigured())->toBeFalse();
        });

        it('handles invalid json in token file', function () {
            file_put_contents($this->tokenFile, 'not valid json');

            $service = new SpotifyService;

            expect($service->isConfigured())->toBeFalse();
        });

        it('handles empty token file', function () {
            file_put_contents($this->tokenFile, '');

            $service = new SpotifyService;

            expect($service->isConfigured())->toBeFalse();
        });

        it('handles token file with null values', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
            ]));

            $service = new SpotifyService;

            expect($service->isConfigured())->toBeFalse();
        });

        it('refreshes expired token automatically', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() - 100, // Expired
            ]));

            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'access_token' => 'new_access_token',
                    'expires_in' => 3600,
                ]),
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service = new SpotifyService;
            $service->getDevices();

            $tokenData = json_decode(file_get_contents($this->tokenFile), true);
            expect($tokenData['access_token'])->toBe('new_access_token');
        });

        it('refreshes token that expires within 60 seconds', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 30, // Expires in 30 seconds
            ]));

            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'access_token' => 'refreshed_token',
                    'expires_in' => 3600,
                ]),
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service = new SpotifyService;
            $service->getDevices();

            $tokenData = json_decode(file_get_contents($this->tokenFile), true);
            expect($tokenData['access_token'])->toBe('refreshed_token');
        });

        it('does not refresh token with plenty of time left', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600, // Not expiring soon
            ]));

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service = new SpotifyService;
            $service->getDevices();

            Http::assertNotSent(function (Request $request) {
                return str_contains($request->url(), 'accounts.spotify.com');
            });
        });

        it('does not refresh without refresh token', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'valid_token',
                'refresh_token' => null,
                'expires_at' => time() - 100, // Expired but no refresh token
            ]));

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service = new SpotifyService;
            $service->getDevices();

            Http::assertNotSent(function (Request $request) {
                return str_contains($request->url(), 'accounts.spotify.com');
            });
        });

        it('handles refresh token failure gracefully', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() - 100,
            ]));

            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'error' => 'invalid_grant',
                    'error_description' => 'Refresh token revoked',
                ], 400),
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service = new SpotifyService;
            $service->getDevices();

            // Token should remain unchanged since refresh failed
            $tokenData = json_decode(file_get_contents($this->tokenFile), true);
            expect($tokenData['access_token'])->toBe('old_token');
        });

        it('creates config directory if it does not exist', function () {
            $tempDir = sys_get_temp_dir().'/spotify_test_dir_'.uniqid();
            $tokenFile = $tempDir.'/token.json';
            Config::set('spotify.token_path', $tokenFile);

            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'test_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() - 100,
            ]));

            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'access_token' => 'new_token',
                    'expires_in' => 3600,
                ]),
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service = new SpotifyService;

            // The directory should be created when saving token
            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('saveTokenData');
            $method->setAccessible(true);
            $method->invoke($service);

            expect(is_dir($tempDir))->toBeTrue();

            // Cleanup
            if (file_exists($tokenFile)) {
                unlink($tokenFile);
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        });
    });

    describe('search', function () {

        it('searches for tracks successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

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

            $result = $service->search('test query');

            expect($result)->toHaveKeys(['uri', 'name', 'artist']);
            expect($result['uri'])->toBe('spotify:track:123');
            expect($result['name'])->toBe('Test Song');
            expect($result['artist'])->toBe('Test Artist');
        });

        it('returns null when no tracks found', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => [
                        'items' => [],
                    ],
                ]),
            ]);

            $result = $service->search('nonexistent song');

            expect($result)->toBeNull();
        });

        it('returns null on failed search request', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'error' => ['message' => 'Bad Request'],
                ], 400),
            ]);

            $result = $service->search('test');

            expect($result)->toBeNull();
        });

        it('handles track with missing artist gracefully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => [
                        'items' => [[
                            'uri' => 'spotify:track:123',
                            'name' => 'Test Song',
                            'artists' => [],
                        ]],
                    ],
                ]),
            ]);

            $result = $service->search('test');

            expect($result['artist'])->toBe('Unknown');
        });

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->search('test');
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');

        it('searches multiple tracks successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

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
                            [
                                'uri' => 'spotify:track:3',
                                'name' => 'Song 3',
                                'artists' => [['name' => 'Artist 3']],
                                'album' => ['name' => 'Album 3'],
                            ],
                        ],
                    ],
                ]),
            ]);

            $results = $service->searchMultiple('test', 'track', 10);

            expect($results)->toHaveCount(3);
            expect($results[0])->toHaveKeys(['uri', 'name', 'artist', 'album']);
            expect($results[0]['name'])->toBe('Song 1');
            expect($results[1]['name'])->toBe('Song 2');
            expect($results[2]['album'])->toBe('Album 3');
        });

        it('returns empty array when searchMultiple finds nothing', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => ['items' => []],
                ]),
            ]);

            $results = $service->searchMultiple('nonexistent');

            expect($results)->toBeArray()->toBeEmpty();
        });

        it('returns empty array on failed searchMultiple request', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([], 500),
            ]);

            $results = $service->searchMultiple('test');

            expect($results)->toBeArray()->toBeEmpty();
        });

        it('throws exception when searchMultiple not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->searchMultiple('test');
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('play', function () {

        it('plays a track on active device', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'device1', 'is_active' => true],
                    ],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $service->play('spotify:track:123');

            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT' || !str_contains($request->url(), 'me/player/play')) {
                    return false;
                }
                $body = json_decode($request->body(), true);
                return $body['uris'] === ['spotify:track:123'] && $body['device_id'] === 'device1';
            });
        });

        it('activates inactive device before playing', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'device1', 'is_active' => false],
                    ],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $service->play('spotify:track:123');

            // Verify transfer was called
            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT' || !str_contains($request->url(), 'me/player')) {
                    return false;
                }
                if (str_contains($request->url(), 'me/player/play')) {
                    return false;
                }
                $body = json_decode($request->body(), true);
                return isset($body['device_ids']) && $body['device_ids'] === ['device1'];
            });
        });

        it('plays on specific device when provided', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'device1', 'is_active' => true],
                        ['id' => 'device2', 'is_active' => false],
                    ],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $service->play('spotify:track:123', 'device2');

            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT' || !str_contains($request->url(), 'me/player/play')) {
                    return false;
                }
                $body = json_decode($request->body(), true);
                return $body['device_id'] === 'device2';
            });
        });

        it('throws exception when no devices available', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service->play('spotify:track:123');
        })->throws(Exception::class, 'No Spotify devices available. Open Spotify on any device.');

        it('throws exception on play failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([
                    'error' => ['message' => 'Player command failed: Restriction violated'],
                ], 403),
            ]);

            $service->play('spotify:track:123');
        })->throws(Exception::class, 'Player command failed: Restriction violated');

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->play('spotify:track:123');
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('pause', function () {

        it('pauses playback successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/pause' => Http::response([], 204),
            ]);

            $service->pause();

            Http::assertSent(function (Request $request) {
                return $request->method() === 'PUT' && str_contains($request->url(), 'me/player/pause');
            });
        });

        it('throws exception on pause failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/pause' => Http::response([
                    'error' => ['message' => 'Player command failed: No active device found'],
                ], 404),
            ]);

            $service->pause();
        })->throws(Exception::class, 'Player command failed: No active device found');

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->pause();
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('resume', function () {

        it('resumes playback on active device', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $service->resume();

            Http::assertSent(function (Request $request) {
                return $request->method() === 'PUT' && str_contains($request->url(), 'me/player/play');
            });
        });

        it('transfers and resumes on inactive device', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => false]],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
            ]);

            $service->resume();

            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT' || str_contains($request->url(), 'me/player/play')) {
                    return false;
                }
                $body = json_decode($request->body(), true);
                return isset($body['play']) && $body['play'] === true;
            });
        });

        it('resumes on specific device when provided', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $service->resume('specific_device');

            Http::assertSent(function (Request $request) {
                $body = json_decode($request->body(), true);
                return isset($body['device_id']) && $body['device_id'] === 'specific_device';
            });
        });

        it('throws exception when no devices available', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service->resume();
        })->throws(Exception::class, 'No Spotify devices available. Open Spotify on any device.');

        it('throws exception on resume failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([
                    'error' => ['message' => 'Premium required'],
                ], 403),
            ]);

            $service->resume();
        })->throws(Exception::class, 'Premium required');

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->resume();
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('next', function () {

        it('skips to next track successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/next' => Http::response([], 204),
            ]);

            $service->next();

            Http::assertSent(function (Request $request) {
                return $request->method() === 'POST' && str_contains($request->url(), 'me/player/next');
            });
        });

        it('throws exception on next failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/next' => Http::response([
                    'error' => ['message' => 'No next track'],
                ], 403),
            ]);

            $service->next();
        })->throws(Exception::class, 'No next track');

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->next();
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('previous', function () {

        it('skips to previous track successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/previous' => Http::response([], 204),
            ]);

            $service->previous();

            Http::assertSent(function (Request $request) {
                return $request->method() === 'POST' && str_contains($request->url(), 'me/player/previous');
            });
        });

        it('throws exception on previous failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/previous' => Http::response([
                    'error' => ['message' => 'No previous track'],
                ], 403),
            ]);

            $service->previous();
        })->throws(Exception::class, 'No previous track');

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->previous();
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('volume', function () {

        it('sets volume successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            $result = $service->setVolume(50);

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=50');
            });
        });

        it('clamps volume to maximum 100', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            $service->setVolume(150);

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=100');
            });
        });

        it('clamps volume to minimum 0', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 204),
            ]);

            $service->setVolume(-50);

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'volume_percent=0');
            });
        });

        it('returns false on volume failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/volume*' => Http::response([], 404),
            ]);

            $result = $service->setVolume(50);

            expect($result)->toBeFalse();
        });

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->setVolume(50);
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('devices', function () {

        it('gets available devices', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        [
                            'id' => 'device1',
                            'name' => 'MacBook Pro',
                            'type' => 'Computer',
                            'is_active' => true,
                            'volume_percent' => 75,
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

            $devices = $service->getDevices();

            expect($devices)->toHaveCount(2);
            expect($devices[0]['name'])->toBe('MacBook Pro');
            expect($devices[0]['is_active'])->toBeTrue();
            expect($devices[1]['name'])->toBe('iPhone');
        });

        it('returns empty array when no devices', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $devices = $service->getDevices();

            expect($devices)->toBeArray()->toBeEmpty();
        });

        it('returns empty array on devices failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([], 500),
            ]);

            $devices = $service->getDevices();

            expect($devices)->toBeArray()->toBeEmpty();
        });

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->getDevices();
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');

        it('finds active device first', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'inactive1', 'is_active' => false, 'name' => 'Device 1'],
                        ['id' => 'active', 'is_active' => true, 'name' => 'Active Device'],
                        ['id' => 'inactive2', 'is_active' => false, 'name' => 'Device 2'],
                    ],
                ]),
            ]);

            $device = $service->getActiveDevice();

            expect($device['id'])->toBe('active');
            expect($device['is_active'])->toBeTrue();
        });

        it('returns first device when none active', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'first', 'is_active' => false, 'name' => 'First Device'],
                        ['id' => 'second', 'is_active' => false, 'name' => 'Second Device'],
                    ],
                ]),
            ]);

            $device = $service->getActiveDevice();

            expect($device['id'])->toBe('first');
        });

        it('returns null when no devices available', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $device = $service->getActiveDevice();

            expect($device)->toBeNull();
        });
    });

    describe('transferPlayback', function () {

        it('transfers playback to device successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([], 204),
            ]);

            $service->transferPlayback('device123', true);

            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT') {
                    return false;
                }
                $body = json_decode($request->body(), true);
                return $body['device_ids'] === ['device123'] && $body['play'] === true;
            });
        });

        it('transfers playback without starting play', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([], 204),
            ]);

            $service->transferPlayback('device123', false);

            Http::assertSent(function (Request $request) {
                $body = json_decode($request->body(), true);
                return $body['play'] === false;
            });
        });

        it('throws exception on transfer failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'error' => ['message' => 'Device not found'],
                ], 404),
            ]);

            $service->transferPlayback('invalid_device');
        })->throws(Exception::class, 'Device not found');

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->transferPlayback('device123');
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('queue', function () {

        it('gets queue successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/queue' => Http::response([
                    'currently_playing' => [
                        'name' => 'Current Track',
                        'uri' => 'spotify:track:current',
                    ],
                    'queue' => [
                        ['name' => 'Next Track 1', 'uri' => 'spotify:track:1'],
                        ['name' => 'Next Track 2', 'uri' => 'spotify:track:2'],
                        ['name' => 'Next Track 3', 'uri' => 'spotify:track:3'],
                    ],
                ]),
            ]);

            $queue = $service->getQueue();

            expect($queue)->toHaveKeys(['currently_playing', 'queue']);
            expect($queue['currently_playing']['name'])->toBe('Current Track');
            expect($queue['queue'])->toHaveCount(3);
            expect($queue['queue'][0]['name'])->toBe('Next Track 1');
        });

        it('returns empty array when queue empty', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/queue' => Http::response([
                    'currently_playing' => null,
                    'queue' => [],
                ]),
            ]);

            $queue = $service->getQueue();

            expect($queue['currently_playing'])->toBeNull();
            expect($queue['queue'])->toBeEmpty();
        });

        it('returns empty array on queue failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/queue' => Http::response([], 500),
            ]);

            $queue = $service->getQueue();

            expect($queue)->toBeArray()->toBeEmpty();
        });

        it('returns empty array when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $queue = $service->getQueue();

            expect($queue)->toBeArray()->toBeEmpty();
        });

        it('adds track to queue successfully', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/queue*' => Http::response([], 204),
            ]);

            $service->addToQueue('spotify:track:123');

            Http::assertSent(function (Request $request) {
                return $request->method() === 'POST' &&
                    str_contains($request->url(), 'uri=spotify%3Atrack%3A123') &&
                    str_contains($request->url(), 'device_id=device1');
            });
        });

        it('throws exception when adding to queue without active device', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $service->addToQueue('spotify:track:123');
        })->throws(Exception::class, 'No active Spotify device. Start playing something first.');

        it('throws exception on addToQueue failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/queue*' => Http::response([
                    'error' => ['message' => 'Invalid track URI'],
                ], 400),
            ]);

            $service->addToQueue('invalid:uri');
        })->throws(Exception::class, 'Invalid track URI');

        it('throws exception when adding to queue not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->addToQueue('spotify:track:123');
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('playlists', function () {

        it('gets user playlists', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([
                    'items' => [
                        [
                            'id' => 'playlist1',
                            'name' => 'My Playlist',
                            'tracks' => ['total' => 50],
                            'owner' => ['display_name' => 'User'],
                        ],
                        [
                            'id' => 'playlist2',
                            'name' => 'Another Playlist',
                            'tracks' => ['total' => 25],
                            'owner' => ['display_name' => 'User'],
                        ],
                    ],
                ]),
            ]);

            $playlists = $service->getPlaylists();

            expect($playlists)->toHaveCount(2);
            expect($playlists[0]['name'])->toBe('My Playlist');
            expect($playlists[1]['name'])->toBe('Another Playlist');
        });

        it('gets playlists with custom limit', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([
                    'items' => [],
                ]),
            ]);

            $service->getPlaylists(50);

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'limit=50');
            });
        });

        it('returns empty array when no playlists', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([
                    'items' => [],
                ]),
            ]);

            $playlists = $service->getPlaylists();

            expect($playlists)->toBeArray()->toBeEmpty();
        });

        it('returns empty array on playlists failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([], 500),
            ]);

            $playlists = $service->getPlaylists();

            expect($playlists)->toBeArray()->toBeEmpty();
        });

        it('returns empty array when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $playlists = $service->getPlaylists();

            expect($playlists)->toBeArray()->toBeEmpty();
        });

        it('gets playlist tracks', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/playlists/playlist123/tracks' => Http::response([
                    'items' => [
                        [
                            'track' => [
                                'name' => 'Track 1',
                                'uri' => 'spotify:track:1',
                            ],
                        ],
                        [
                            'track' => [
                                'name' => 'Track 2',
                                'uri' => 'spotify:track:2',
                            ],
                        ],
                    ],
                ]),
            ]);

            $tracks = $service->getPlaylistTracks('playlist123');

            expect($tracks)->toHaveCount(2);
            expect($tracks[0]['track']['name'])->toBe('Track 1');
        });

        it('returns empty array when playlist has no tracks', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/playlists/empty/tracks' => Http::response([
                    'items' => [],
                ]),
            ]);

            $tracks = $service->getPlaylistTracks('empty');

            expect($tracks)->toBeArray()->toBeEmpty();
        });

        it('returns empty array on playlist tracks failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/playlists/invalid/tracks' => Http::response([], 404),
            ]);

            $tracks = $service->getPlaylistTracks('invalid');

            expect($tracks)->toBeArray()->toBeEmpty();
        });

        it('returns empty array for playlist tracks when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $tracks = $service->getPlaylistTracks('playlist123');

            expect($tracks)->toBeArray()->toBeEmpty();
        });

        it('plays a playlist', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $result = $service->playPlaylist('playlist123');

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                if ($request->method() !== 'PUT' || !str_contains($request->url(), 'me/player/play')) {
                    return false;
                }
                $body = json_decode($request->body(), true);
                return $body['context_uri'] === 'spotify:playlist:playlist123';
            });
        });

        it('plays playlist on specific device', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $result = $service->playPlaylist('playlist123', 'specific_device');

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                $body = json_decode($request->body(), true);
                return $body['device_id'] === 'specific_device';
            });
        });

        it('returns false on playPlaylist failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'device1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response([], 403),
            ]);

            $result = $service->playPlaylist('playlist123');

            expect($result)->toBeFalse();
        });

        it('returns false for playPlaylist when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $result = $service->playPlaylist('playlist123');

            expect($result)->toBeFalse();
        });
    });

    describe('shuffle', function () {

        it('enables shuffle', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 204),
            ]);

            $result = $service->setShuffle(true);

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'state=true');
            });
        });

        it('disables shuffle', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 204),
            ]);

            $result = $service->setShuffle(false);

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'state=false');
            });
        });

        it('returns false on shuffle failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 404),
            ]);

            $result = $service->setShuffle(true);

            expect($result)->toBeFalse();
        });

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->setShuffle(true);
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('repeat', function () {

        it('sets repeat to track', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $service->setRepeat('track');

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'state=track');
            });
        });

        it('sets repeat to context', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $service->setRepeat('context');

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'state=context');
            });
        });

        it('sets repeat to off', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $service->setRepeat('off');

            expect($result)->toBeTrue();

            Http::assertSent(function (Request $request) {
                return str_contains($request->url(), 'state=off');
            });
        });

        it('throws exception for invalid repeat state', function () {
            $service = createAuthenticatedService($this->tokenFile);

            $service->setRepeat('invalid');
        })->throws(Exception::class, 'Invalid repeat state. Use: off, track, or context');

        it('returns false on repeat failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 404),
            ]);

            $result = $service->setRepeat('track');

            expect($result)->toBeFalse();
        });

        it('throws exception when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $service->setRepeat('track');
        })->throws(Exception::class, 'Not authenticated. Run "music login" first.');
    });

    describe('currentPlayback', function () {

        it('gets current playback state', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'item' => [
                        'name' => 'Current Song',
                        'artists' => [['name' => 'Artist Name']],
                        'album' => ['name' => 'Album Name'],
                        'duration_ms' => 240000,
                    ],
                    'progress_ms' => 120000,
                    'is_playing' => true,
                    'shuffle_state' => true,
                    'repeat_state' => 'context',
                    'device' => [
                        'id' => 'device123',
                        'name' => 'My Device',
                        'volume_percent' => 65,
                    ],
                ]),
            ]);

            $playback = $service->getCurrentPlayback();

            expect($playback)->toHaveKeys(['name', 'artist', 'album', 'progress_ms', 'duration_ms', 'is_playing', 'shuffle_state', 'repeat_state', 'device']);
            expect($playback['name'])->toBe('Current Song');
            expect($playback['artist'])->toBe('Artist Name');
            expect($playback['album'])->toBe('Album Name');
            expect($playback['progress_ms'])->toBe(120000);
            expect($playback['duration_ms'])->toBe(240000);
            expect($playback['is_playing'])->toBeTrue();
            expect($playback['shuffle_state'])->toBeTrue();
            expect($playback['repeat_state'])->toBe('context');
            expect($playback['device']['volume_percent'])->toBe(65);
        });

        it('handles missing artist in current playback', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'item' => [
                        'name' => 'Song',
                        'artists' => [],
                        'album' => ['name' => 'Album'],
                        'duration_ms' => 180000,
                    ],
                    'progress_ms' => 0,
                    'is_playing' => false,
                ]),
            ]);

            $playback = $service->getCurrentPlayback();

            expect($playback['artist'])->toBe('Unknown');
        });

        it('handles missing album in current playback', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'item' => [
                        'name' => 'Song',
                        'artists' => [['name' => 'Artist']],
                        'duration_ms' => 180000,
                    ],
                    'progress_ms' => 0,
                    'is_playing' => false,
                ]),
            ]);

            $playback = $service->getCurrentPlayback();

            expect($playback['album'])->toBe('Unknown');
        });

        it('returns null when nothing playing', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([]),
            ]);

            $playback = $service->getCurrentPlayback();

            expect($playback)->toBeNull();
        });

        it('returns null on playback failure', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([], 500),
            ]);

            $playback = $service->getCurrentPlayback();

            expect($playback)->toBeNull();
        });

        it('returns null when not authenticated', function () {
            $service = createUnauthenticatedService($this->tokenFile);

            $playback = $service->getCurrentPlayback();

            expect($playback)->toBeNull();
        });

        it('handles missing optional fields in playback state', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'item' => [
                        'name' => 'Song',
                        'artists' => [['name' => 'Artist']],
                        'album' => ['name' => 'Album'],
                    ],
                ]),
            ]);

            $playback = $service->getCurrentPlayback();

            expect($playback['progress_ms'])->toBe(0);
            expect($playback['duration_ms'])->toBe(0);
            expect($playback['is_playing'])->toBeFalse();
            expect($playback['shuffle_state'])->toBeFalse();
            expect($playback['repeat_state'])->toBe('off');
            expect($playback['device'])->toBeNull();
        });
    });

    describe('error handling', function () {

        it('handles generic API error with missing message', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/pause' => Http::response([
                    'error' => [],
                ], 500),
            ]);

            $service->pause();
        })->throws(Exception::class, 'Failed to pause playback');

        it('handles API error with custom message', function () {
            $service = createAuthenticatedService($this->tokenFile);

            Http::fake([
                'api.spotify.com/v1/me/player/next' => Http::response([
                    'error' => ['message' => 'Custom error message from Spotify'],
                ], 403),
            ]);

            $service->next();
        })->throws(Exception::class, 'Custom error message from Spotify');
    });
});
