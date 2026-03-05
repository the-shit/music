<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

// Helper to make a service with optional authentication
function makeService(bool $authenticated = true): SpotifyService
{
    $service = new SpotifyService;

    $mockAuth = Mockery::mock(SpotifyAuthManager::class)->makePartial();

    if ($authenticated) {
        $mockAuth->shouldReceive('getAccessToken')->andReturn('valid_token');
        $mockAuth->shouldReceive('requireAuth')->andReturn(null);
        $mockAuth->shouldReceive('isConfigured')->andReturn(true);
    } else {
        $mockAuth->shouldReceive('requireAuth')->andThrow(new \Exception('Not authenticated. Run "spotify login" first.'));
        $mockAuth->shouldReceive('getAccessToken')->andReturn(null);
        $mockAuth->shouldReceive('isConfigured')->andReturn(false);
    }

    $reflection = new ReflectionClass($service);
    foreach (['auth', 'player', 'discovery'] as $prop) {
        $r = $reflection->getProperty($prop);
        $r->setAccessible(true);
        $obj = $r->getValue($service);
        if ($prop === 'auth') {
            $r->setValue($service, $mockAuth);
        } else {
            $sub = new ReflectionClass($obj);
            $subAuth = $sub->getProperty('auth');
            $subAuth->setAccessible(true);
            $subAuth->setValue($obj, $mockAuth);
        }
    }

    return $service;
}

beforeEach(function () {
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');

    $this->service = makeService();
});

describe('SpotifyService Extended', function () {

    describe('Unauthenticated method throws', function () {

        beforeEach(function () {
            $this->unauthService = makeService(false);
        });

        it('search() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->search('test'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('play() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->play('spotify:track:123'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('resume() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->resume())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('pause() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->pause())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('next() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->next())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('previous() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->previous())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getDevices() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->getDevices())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('transferPlayback() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->transferPlayback('device_id'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('addToQueue() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->addToQueue('spotify:track:123'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('setVolume() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->setVolume(50))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('setShuffle() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->setShuffle(true))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('setRepeat() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->setRepeat('off'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getTopTracks() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->getTopTracks())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getTopArtists() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->getTopArtists())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getRecentlyPlayed() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->getRecentlyPlayed())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getUserProfile() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->getUserProfile())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('searchMultiple() throws when not authenticated', function () {
            expect(fn () => $this->unauthService->searchMultiple('test'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getPlaylists() returns empty array when not authenticated', function () {
            $result = $this->unauthService->getPlaylists();
            expect($result)->toBe([]);
        });

        it('getPlaylistTracks() returns empty array when not authenticated', function () {
            $result = $this->unauthService->getPlaylistTracks('playlist123');
            expect($result)->toBe([]);
        });

        it('getQueue() returns empty array when not authenticated', function () {
            $result = $this->unauthService->getQueue();
            expect($result)->toBe([]);
        });

        it('getCurrentPlayback() returns null when not authenticated', function () {
            $result = $this->unauthService->getCurrentPlayback();
            expect($result)->toBeNull();
        });

        it('playPlaylist() returns false when not authenticated', function () {
            $result = $this->unauthService->playPlaylist('playlist123');
            expect($result)->toBeFalse();
        });

    });

    describe('API failure handling', function () {

        it('pause() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/pause' => Http::response(
                    ['error' => ['message' => 'Player error']],
                    403
                ),
            ]);

            expect(fn () => $this->service->pause())
                ->toThrow(\Exception::class, 'Player error');
        });

        it('next() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/next' => Http::response(
                    ['error' => ['message' => 'Skip failed']],
                    403
                ),
            ]);

            expect(fn () => $this->service->next())
                ->toThrow(\Exception::class, 'Skip failed');
        });

        it('previous() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/previous' => Http::response(
                    ['error' => ['message' => 'Previous failed']],
                    403
                ),
            ]);

            expect(fn () => $this->service->previous())
                ->toThrow(\Exception::class, 'Previous failed');
        });

        it('transferPlayback() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response(
                    ['error' => ['message' => 'Transfer failed']],
                    403
                ),
            ]);

            expect(fn () => $this->service->transferPlayback('device123'))
                ->toThrow(\Exception::class, 'Transfer failed');
        });

        it('addToQueue() throws when no active device', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            expect(fn () => $this->service->addToQueue('spotify:track:123'))
                ->toThrow(\Exception::class, 'No active Spotify device');
        });

        it('addToQueue() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'dev1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/queue*' => Http::response(
                    ['error' => ['message' => 'Queue error']],
                    403
                ),
            ]);

            expect(fn () => $this->service->addToQueue('spotify:track:123'))
                ->toThrow(\Exception::class, 'Queue error');
        });

        it('play() throws when no device available', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            expect(fn () => $this->service->play('spotify:track:123'))
                ->toThrow(\Exception::class, 'No Spotify devices available');
        });

        it('play() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'dev1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response(
                    ['error' => ['message' => 'Play failed']],
                    403
                ),
            ]);

            expect(fn () => $this->service->play('spotify:track:123'))
                ->toThrow(\Exception::class, 'Play failed');
        });

        it('resume() throws when no device available', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            expect(fn () => $this->service->resume())
                ->toThrow(\Exception::class, 'No Spotify devices available');
        });

        it('resume() throws on API failure', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'dev1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response(
                    ['error' => ['message' => 'Resume failed']],
                    403
                ),
            ]);

            expect(fn () => $this->service->resume())
                ->toThrow(\Exception::class, 'Resume failed');
        });

        it('setRepeat() throws on invalid state', function () {
            expect(fn () => $this->service->setRepeat('invalid'))
                ->toThrow(\Exception::class, 'Invalid repeat state');
        });

        it('play() with inactive device transfers playback first', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'inactive_dev', 'is_active' => false, 'name' => 'Inactive']],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            // Should not throw — transfers then plays
            $this->service->play('spotify:track:abc');
            expect(true)->toBeTrue(); // got here without exception
        });

        it('play() with specific device id transfers if inactive', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'target_dev', 'is_active' => false, 'name' => 'Target'],
                    ],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $this->service->play('spotify:track:abc', 'target_dev');
            expect(true)->toBeTrue();
        });

        it('resume() with inactive device transfers playback', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'inactive_dev', 'is_active' => false]],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
            ]);

            // Should not throw (transfers with play=true and returns)
            $this->service->resume();
            expect(true)->toBeTrue();
        });

    });

    describe('Playlist tracks', function () {

        it('gets playlist tracks', function () {
            Http::fake([
                'api.spotify.com/v1/playlists/*/tracks' => Http::response([
                    'items' => [
                        ['track' => ['name' => 'Track 1']],
                        ['track' => ['name' => 'Track 2']],
                    ],
                ]),
            ]);

            $tracks = $this->service->getPlaylistTracks('playlist123');

            expect($tracks)->toHaveCount(2);
            expect($tracks[0]['track']['name'])->toBe('Track 1');
        });

        it('returns empty array when playlist tracks API fails', function () {
            Http::fake([
                'api.spotify.com/v1/playlists/*/tracks' => Http::response([], 500),
            ]);

            $result = $this->service->getPlaylistTracks('playlist123');
            expect($result)->toBe([]);
        });

    });

    describe('User profile', function () {

        it('gets user profile', function () {
            Http::fake([
                'api.spotify.com/v1/me' => Http::response([
                    'id' => 'user123',
                    'display_name' => 'Test User',
                    'email' => 'test@example.com',
                ]),
            ]);

            $profile = $this->service->getUserProfile();

            expect($profile)->toHaveKey('id');
            expect($profile['id'])->toBe('user123');
        });

        it('returns null when user profile API fails', function () {
            Http::fake([
                'api.spotify.com/v1/me' => Http::response([], 500),
            ]);

            $result = $this->service->getUserProfile();
            expect($result)->toBeNull();
        });

    });

    describe('Playback state control', function () {

        it('sets shuffle on', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 204),
            ]);

            $result = $this->service->setShuffle(true);
            expect($result)->toBeTrue();

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'state=true');
            });
        });

        it('sets shuffle off', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 204),
            ]);

            $result = $this->service->setShuffle(false);
            expect($result)->toBeTrue();

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'state=false');
            });
        });

        it('sets repeat to track', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $this->service->setRepeat('track');
            expect($result)->toBeTrue();
        });

        it('sets repeat to context', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $this->service->setRepeat('context');
            expect($result)->toBeTrue();
        });

        it('sets repeat to off', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $this->service->setRepeat('off');
            expect($result)->toBeTrue();
        });

    });

    describe('Search edge cases', function () {

        it('returns null when search returns no results', function () {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => ['items' => []],
                ]),
            ]);

            $result = $this->service->search('nothing');
            expect($result)->toBeNull();
        });

        it('returns null when search API fails', function () {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([], 500),
            ]);

            $result = $this->service->search('test');
            expect($result)->toBeNull();
        });

        it('returns empty array when searchMultiple API fails', function () {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([], 500),
            ]);

            $result = $this->service->searchMultiple('test');
            expect($result)->toBe([]);
        });

    });

    describe('Device edge cases', function () {

        it('returns empty array when devices API fails', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([], 500),
            ]);

            $result = $this->service->getDevices();
            expect($result)->toBe([]);
        });

        it('getActiveDevice returns null when no devices', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $result = $this->service->getActiveDevice();
            expect($result)->toBeNull();
        });

        it('getActiveDevice returns first device when none active', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'first', 'is_active' => false, 'name' => 'First'],
                        ['id' => 'second', 'is_active' => false, 'name' => 'Second'],
                    ],
                ]),
            ]);

            $result = $this->service->getActiveDevice();
            expect($result['id'])->toBe('first');
        });

    });

    describe('Playlist and queue edge cases', function () {

        it('returns empty array when playlists API fails', function () {
            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([], 500),
            ]);

            $result = $this->service->getPlaylists();
            expect($result)->toBe([]);
        });

        it('returns empty array when queue API fails', function () {
            Http::fake([
                'api.spotify.com/v1/me/player/queue' => Http::response([], 500),
            ]);

            $result = $this->service->getQueue();
            expect($result)->toBe([]);
        });

    });

    describe('getCurrentPlayback edge cases', function () {

        it('returns null when no item in playback response', function () {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'is_playing' => false,
                    // no 'item' key
                ]),
            ]);

            $result = $this->service->getCurrentPlayback();
            expect($result)->toBeNull();
        });

        it('returns null when API fails', function () {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([], 500),
            ]);

            $result = $this->service->getCurrentPlayback();
            expect($result)->toBeNull();
        });

    });

});
