<?php

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Gate;

describe('Gate Authorization', function () {

    describe('Play Command Authorization', function () {

        test('play command requires user-modify-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            $this->artisan('play', ['query' => 'test song'])
                ->expectsOutput('❌ Missing required scope: user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('play command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('search')
                    ->once()
                    ->with('test song')
                    ->andReturn([
                        'uri' => 'spotify:track:123',
                        'name' => 'Test Song',
                        'artist' => 'Test Artist',
                    ]);
                $mock->shouldReceive('play')
                    ->once()
                    ->with('spotify:track:123', null);
            });

            $this->artisan('play', ['query' => 'test song'])
                ->assertExitCode(0);
        });

        test('play command shows JSON error when unauthorized with --json flag', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            $this->artisan('play', ['query' => 'test', '--json' => true])
                ->expectsOutputToContain('"error":true')
                ->expectsOutputToContain('user-modify-playback-state')
                ->assertExitCode(1);
        });
    });

    describe('Pause Command Authorization', function () {

        test('pause command requires user-modify-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            $this->artisan('pause')
                ->expectsOutput('❌ Missing required scope: user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('pause command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'progress_ms' => 60000,
                ]);
                $mock->shouldReceive('pause')->once();
            });

            $this->artisan('pause')
                ->assertExitCode(0);
        });
    });

    describe('Resume Command Authorization', function () {

        test('resume command requires user-modify-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            $this->artisan('resume')
                ->expectsOutput('❌ Missing required scope: user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('resume command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('resume')->once();
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                ]);
            });

            $this->artisan('resume')
                ->assertExitCode(0);
        });
    });

    describe('Skip Command Authorization', function () {

        test('skip command requires user-modify-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            $this->artisan('skip')
                ->expectsOutput('❌ Missing required scope: user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('skip command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                ]);
                $mock->shouldReceive('next')->once();
            });

            $this->artisan('skip')
                ->assertExitCode(0);
        });
    });

    describe('Volume Command Authorization', function () {

        test('volume command requires user-modify-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            $this->artisan('volume', ['level' => '50'])
                ->expectsOutput('❌ Missing required scope: user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('volume command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('setVolume')->once()->with(50)->andReturn(true);
            });

            $this->artisan('volume', ['level' => '50'])
                ->assertExitCode(0);
        });
    });

    describe('Shuffle Command Authorization', function () {

        test('shuffle command requires both read and modify playback scopes', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            $this->artisan('shuffle', ['state' => 'on'])
                ->expectsOutput('❌ Missing required scope: user-read-playback-state, user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('shuffle command succeeds with proper scopes', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'shuffle_state' => false,
                ]);
                $mock->shouldReceive('setShuffle')->once()->with(true)->andReturn(true);
            });

            $this->artisan('shuffle', ['state' => 'on'])
                ->assertExitCode(0);
        });
    });

    describe('Repeat Command Authorization', function () {

        test('repeat command requires both read and modify playback scopes', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
            });

            $this->artisan('repeat', ['state' => 'track'])
                ->expectsOutput('❌ Missing required scope: user-read-playback-state, user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('repeat command succeeds with proper scopes', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'repeat_state' => 'off',
                ]);
                $mock->shouldReceive('setRepeat')->once()->with('track')->andReturn(true);
            });

            $this->artisan('repeat', ['state' => 'track'])
                ->assertExitCode(0);
        });
    });

    describe('Current Command Authorization', function () {

        test('current command requires read playback scopes', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
            });

            $this->artisan('current')
                ->expectsOutput('❌ Missing required scope: user-read-playback-state, user-read-currently-playing')
                ->assertExitCode(1);
        });

        test('current command succeeds with proper scopes', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                    'user-read-currently-playing',
                ]);
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                    'progress_ms' => 60000,
                    'duration_ms' => 180000,
                    'is_playing' => true,
                ]);
            });

            $this->artisan('current')
                ->assertExitCode(0);
        });
    });

    describe('Queue Command Authorization', function () {

        test('queue command requires user-modify-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            $this->artisan('queue', ['query' => 'test song'])
                ->expectsOutput('❌ Missing required scope: user-modify-playback-state')
                ->assertExitCode(1);
        });

        test('queue command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
                $mock->shouldReceive('search')
                    ->once()
                    ->with('test song')
                    ->andReturn([
                        'uri' => 'spotify:track:123',
                        'name' => 'Test Song',
                        'artist' => 'Test Artist',
                    ]);
                $mock->shouldReceive('addToQueue')->once()->with('spotify:track:123');
            });

            $this->artisan('queue', ['query' => 'test song'])
                ->assertExitCode(0);
        });
    });

    describe('Devices Command Authorization', function () {

        test('devices command requires user-read-playback-state scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            $this->artisan('devices')
                ->expectsOutput('❌ Missing required scope: user-read-playback-state')
                ->assertExitCode(1);
        });

        test('devices command succeeds with proper scope', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
                $mock->shouldReceive('getDevices')->andReturn([
                    [
                        'id' => 'device1',
                        'name' => 'MacBook',
                        'type' => 'Computer',
                        'is_active' => true,
                        'volume_percent' => 50,
                    ],
                ]);
            });

            $this->artisan('devices')
                ->assertExitCode(0);
        });
    });

    describe('Authorization with all scopes', function () {

        test('commands work with all required scopes granted', function () {
            $allScopes = [
                'user-read-playback-state',
                'user-modify-playback-state',
                'user-read-currently-playing',
                'streaming',
                'playlist-read-private',
                'playlist-read-collaborative',
            ];

            $this->mock(SpotifyService::class, function ($mock) use ($allScopes) {
                $mock->shouldReceive('isConfigured')->andReturn(true);
                $mock->shouldReceive('getGrantedScopes')->andReturn($allScopes);
                $mock->shouldReceive('getCurrentPlayback')->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                    'progress_ms' => 60000,
                    'duration_ms' => 180000,
                    'is_playing' => true,
                    'shuffle_state' => false,
                    'repeat_state' => 'off',
                ]);
            });

            // Current command should work
            $this->artisan('current')
                ->assertExitCode(0);
        });
    });
});
