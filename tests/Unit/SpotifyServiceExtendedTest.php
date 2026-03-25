<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

// Helper to make services with optional authentication
function makeServices(bool $authenticated = true): array
{
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

    return [
        'player' => new SpotifyPlayerService($mockAuth),
        'discovery' => new SpotifyDiscoveryService($mockAuth),
    ];
}

beforeEach(function (): void {
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');

    $services = makeServices();
    $this->playerService = $services['player'];
    $this->discoveryService = $services['discovery'];
});

describe('SpotifyService Extended', function (): void {

    describe('Unauthenticated method throws', function (): void {

        beforeEach(function (): void {
            $services = makeServices(false);
            $this->unauthPlayer = $services['player'];
            $this->unauthDiscovery = $services['discovery'];
        });

        it('search() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthDiscovery->search('test'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('play() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->play('spotify:track:123'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('resume() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->resume())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('pause() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->pause())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('next() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->next())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('previous() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->previous())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getDevices() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->getDevices())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('transferPlayback() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->transferPlayback('device_id'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('addToQueue() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->addToQueue('spotify:track:123'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('setVolume() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->setVolume(50))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('setShuffle() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->setShuffle(true))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('setRepeat() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthPlayer->setRepeat('off'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getTopTracks() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthDiscovery->getTopTracks())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getTopArtists() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthDiscovery->getTopArtists())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getRecentlyPlayed() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthDiscovery->getRecentlyPlayed())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getUserProfile() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthDiscovery->getUserProfile())
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('searchMultiple() throws when not authenticated', function (): void {
            expect(fn () => $this->unauthDiscovery->searchMultiple('test'))
                ->toThrow(\Exception::class, 'Not authenticated');
        });

        it('getPlaylists() returns empty array when not authenticated', function (): void {
            $result = $this->unauthDiscovery->getPlaylists();
            expect($result)->toBe([]);
        });

        it('getPlaylistTracks() returns empty array when not authenticated', function (): void {
            $result = $this->unauthDiscovery->getPlaylistTracks('playlist123');
            expect($result)->toBe([]);
        });

        it('getQueue() returns empty array when not authenticated', function (): void {
            $result = $this->unauthPlayer->getQueue();
            expect($result)->toBe([]);
        });

        it('getCurrentPlayback() returns null when not authenticated', function (): void {
            $result = $this->unauthPlayer->getCurrentPlayback();
            expect($result)->toBeNull();
        });

        it('playPlaylist() returns false when not authenticated', function (): void {
            $result = $this->unauthPlayer->playPlaylist('playlist123');
            expect($result)->toBeFalse();
        });

    });

    describe('API failure handling', function (): void {

        it('pause() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/pause' => Http::response(
                    ['error' => ['message' => 'Player error']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->pause())
                ->toThrow(\Exception::class, 'Player error');
        });

        it('next() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/next' => Http::response(
                    ['error' => ['message' => 'Skip failed']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->next())
                ->toThrow(\Exception::class, 'Skip failed');
        });

        it('previous() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/previous' => Http::response(
                    ['error' => ['message' => 'Previous failed']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->previous())
                ->toThrow(\Exception::class, 'Previous failed');
        });

        it('transferPlayback() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response(
                    ['error' => ['message' => 'Transfer failed']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->transferPlayback('device123'))
                ->toThrow(\Exception::class, 'Transfer failed');
        });

        it('addToQueue() throws when no active device', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            expect(fn () => $this->playerService->addToQueue('spotify:track:123'))
                ->toThrow(\Exception::class, 'No active Spotify device');
        });

        it('addToQueue() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'dev1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/queue*' => Http::response(
                    ['error' => ['message' => 'Queue error']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->addToQueue('spotify:track:123'))
                ->toThrow(\Exception::class, 'Queue error');
        });

        it('play() throws when no device available', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            expect(fn () => $this->playerService->play('spotify:track:123'))
                ->toThrow(\Exception::class, 'No Spotify devices available');
        });

        it('play() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'dev1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response(
                    ['error' => ['message' => 'Play failed']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->play('spotify:track:123'))
                ->toThrow(\Exception::class, 'Play failed');
        });

        it('resume() throws when no device available', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            expect(fn () => $this->playerService->resume())
                ->toThrow(\Exception::class, 'No Spotify devices available');
        });

        it('resume() throws on API failure', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'dev1', 'is_active' => true]],
                ]),
                'api.spotify.com/v1/me/player/play' => Http::response(
                    ['error' => ['message' => 'Resume failed']],
                    403
                ),
            ]);

            expect(fn () => $this->playerService->resume())
                ->toThrow(\Exception::class, 'Resume failed');
        });

        it('setRepeat() throws on invalid state', function (): void {
            expect(fn () => $this->playerService->setRepeat('invalid'))
                ->toThrow(\Exception::class, 'Invalid repeat state');
        });

        it('play() with inactive device transfers playback first', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'inactive_dev', 'is_active' => false, 'name' => 'Inactive']],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            // Should not throw — transfers then plays
            $this->playerService->play('spotify:track:abc');
            expect(true)->toBeTrue(); // got here without exception
        });

        it('play() with specific device id transfers if inactive', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'target_dev', 'is_active' => false, 'name' => 'Target'],
                    ],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
                'api.spotify.com/v1/me/player/play' => Http::response([], 204),
            ]);

            $this->playerService->play('spotify:track:abc', 'target_dev');
            expect(true)->toBeTrue();
        });

        it('resume() with inactive device transfers playback', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [['id' => 'inactive_dev', 'is_active' => false]],
                ]),
                'api.spotify.com/v1/me/player' => Http::response([], 204),
            ]);

            // Should not throw (transfers with play=true and returns)
            $this->playerService->resume();
            expect(true)->toBeTrue();
        });

    });

    describe('Playlist tracks', function (): void {

        it('gets playlist tracks', function (): void {
            Http::fake([
                'api.spotify.com/v1/playlists/*/tracks' => Http::response([
                    'items' => [
                        ['track' => ['name' => 'Track 1']],
                        ['track' => ['name' => 'Track 2']],
                    ],
                ]),
            ]);

            $tracks = $this->discoveryService->getPlaylistTracks('playlist123');

            expect($tracks)->toHaveCount(2);
            expect($tracks[0]['track']['name'])->toBe('Track 1');
        });

        it('returns empty array when playlist tracks API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/playlists/*/tracks' => Http::response([], 500),
            ]);

            $result = $this->discoveryService->getPlaylistTracks('playlist123');
            expect($result)->toBe([]);
        });

    });

    describe('User profile', function (): void {

        it('gets user profile', function (): void {
            Http::fake([
                'api.spotify.com/v1/me' => Http::response([
                    'id' => 'user123',
                    'display_name' => 'Test User',
                    'email' => 'test@example.com',
                ]),
            ]);

            $profile = $this->discoveryService->getUserProfile();

            expect($profile)->toHaveKey('id');
            expect($profile['id'])->toBe('user123');
        });

        it('returns null when user profile API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/me' => Http::response([], 500),
            ]);

            $result = $this->discoveryService->getUserProfile();
            expect($result)->toBeNull();
        });

    });

    describe('Playback state control', function (): void {

        it('sets shuffle on', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 204),
            ]);

            $result = $this->playerService->setShuffle(true);
            expect($result)->toBeTrue();

            Http::assertSent(function ($request): bool {
                return str_contains($request->url(), 'state=true');
            });
        });

        it('sets shuffle off', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/shuffle*' => Http::response([], 204),
            ]);

            $result = $this->playerService->setShuffle(false);
            expect($result)->toBeTrue();

            Http::assertSent(function ($request): bool {
                return str_contains($request->url(), 'state=false');
            });
        });

        it('sets repeat to track', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $this->playerService->setRepeat('track');
            expect($result)->toBeTrue();
        });

        it('sets repeat to context', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $this->playerService->setRepeat('context');
            expect($result)->toBeTrue();
        });

        it('sets repeat to off', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/repeat*' => Http::response([], 204),
            ]);

            $result = $this->playerService->setRepeat('off');
            expect($result)->toBeTrue();
        });

    });

    describe('Search edge cases', function (): void {

        it('returns null when search returns no results', function (): void {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([
                    'tracks' => ['items' => []],
                ]),
            ]);

            $result = $this->discoveryService->search('nothing');
            expect($result)->toBeNull();
        });

        it('returns null when search API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([], 500),
            ]);

            $result = $this->discoveryService->search('test');
            expect($result)->toBeNull();
        });

        it('returns empty array when searchMultiple API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/search*' => Http::response([], 500),
            ]);

            $result = $this->discoveryService->searchMultiple('test');
            expect($result)->toBe([]);
        });

    });

    describe('Device edge cases', function (): void {

        it('returns empty array when devices API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([], 500),
            ]);

            $result = $this->playerService->getDevices();
            expect($result)->toBe([]);
        });

        it('getActiveDevice returns null when no devices', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [],
                ]),
            ]);

            $result = $this->playerService->getActiveDevice();
            expect($result)->toBeNull();
        });

        it('getActiveDevice returns first device when none active', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/devices' => Http::response([
                    'devices' => [
                        ['id' => 'first', 'is_active' => false, 'name' => 'First'],
                        ['id' => 'second', 'is_active' => false, 'name' => 'Second'],
                    ],
                ]),
            ]);

            $result = $this->playerService->getActiveDevice();
            expect($result['id'])->toBe('first');
        });

    });

    describe('Playlist and queue edge cases', function (): void {

        it('returns empty array when playlists API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/playlists*' => Http::response([], 500),
            ]);

            $result = $this->discoveryService->getPlaylists();
            expect($result)->toBe([]);
        });

        it('returns empty array when queue API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player/queue' => Http::response([], 500),
            ]);

            $result = $this->playerService->getQueue();
            expect($result)->toBe([]);
        });

    });

    describe('getCurrentPlayback edge cases', function (): void {

        it('returns null when no item in playback response', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([
                    'is_playing' => false,
                    // no 'item' key
                ]),
            ]);

            $result = $this->playerService->getCurrentPlayback();
            expect($result)->toBeNull();
        });

        it('returns null when API fails', function (): void {
            Http::fake([
                'api.spotify.com/v1/me/player' => Http::response([], 500),
            ]);

            $result = $this->playerService->getCurrentPlayback();
            expect($result)->toBeNull();
        });

    });

});
