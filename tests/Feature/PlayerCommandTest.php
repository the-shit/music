<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

describe('PlayerCommand', function (): void {

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('player')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    it('requires interactive terminal', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });

        $this->artisan('player', ['--no-interaction' => true])
            ->expectsOutputToContain('❌ Player requires an interactive terminal')
            ->expectsOutputToContain('💡 Run without piping or in a proper terminal')
            ->assertExitCode(1);
    });

    it('shows nothing playing state', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $this->artisan('player')
            ->expectsOutputToContain('🎵 Spotify Interactive Player')
            ->expectsOutputToContain('Loading...');
    });

    it('displays current track info', function (): void {
        $currentTrack = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'progress_ms' => 90000,
            'duration_ms' => 180000,
            'is_playing' => true,
            'device' => [
                'volume_percent' => 50,
            ],
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentTrack): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentTrack);
        });

        $this->artisan('player')
            ->expectsOutputToContain('🎵 Spotify Interactive Player')
            ->expectsOutputToContain('Loading...');
    });

});
