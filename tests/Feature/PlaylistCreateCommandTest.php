<?php

use App\Services\SpotifyService;

describe('PlaylistCreateCommand', function (): void {

    it('creates a private playlist by default', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('createPlaylist')
                ->once()
                ->with('My Playlist', '', false)
                ->andReturn([
                    'id' => 'playlist123',
                    'name' => 'My Playlist',
                    'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/playlist123'],
                ]);
        });

        $this->artisan('playlist:create', ['name' => 'My Playlist'])
            ->assertExitCode(0);
    });

    it('creates a public playlist when flagged', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('createPlaylist')
                ->once()
                ->with('Public Jams', '', true)
                ->andReturn([
                    'id' => 'playlist456',
                    'name' => 'Public Jams',
                    'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/playlist456'],
                ]);
        });

        $this->artisan('playlist:create', ['name' => 'Public Jams', '--public' => true])
            ->assertExitCode(0);
    });

    it('passes description to spotify', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('createPlaylist')
                ->once()
                ->with('Coding Music', 'Tracks for deep work', false)
                ->andReturn([
                    'id' => 'playlist789',
                    'name' => 'Coding Music',
                    'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/playlist789'],
                ]);
        });

        $this->artisan('playlist:create', [
            'name' => 'Coding Music',
            '--description' => 'Tracks for deep work',
        ])->assertExitCode(0);
    });

    it('outputs json when requested', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('createPlaylist')
                ->once()
                ->andReturn([
                    'id' => 'playlist123',
                    'name' => 'Test',
                    'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/playlist123'],
                ]);
        });

        $this->artisan('playlist:create', ['name' => 'Test', '--json' => true])
            ->expectsOutputToContain('{"created":true,"id":"playlist123","name":"Test","url":"https:\/\/open.spotify.com\/playlist\/playlist123","public":false}')
            ->assertExitCode(0);
    });

    it('handles api failure', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('createPlaylist')
                ->once()
                ->andThrow(new \Exception('Unauthorized'));
        });

        $this->artisan('playlist:create', ['name' => 'Fail'])
            ->assertExitCode(1);
    });

    it('handles null result from api', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('createPlaylist')
                ->once()
                ->andReturn(null);
        });

        $this->artisan('playlist:create', ['name' => 'Fail'])
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('playlist:create', ['name' => 'Test'])
            ->assertExitCode(1);
    });

});
