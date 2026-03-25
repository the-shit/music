<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

describe('CurrentCommand', function (): void {

    it('shows current track information', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Bohemian Rhapsody',
                'artist' => 'Queen',
                'album' => 'A Night at the Opera',
                'progress_ms' => 90000, // 1:30
                'duration_ms' => 354000, // 5:54
                'is_playing' => true,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('Currently Playing:')
            ->expectsOutputToContain('Bohemian Rhapsody')
            ->expectsOutputToContain('Queen')
            ->expectsOutputToContain('A Night at the Opera')
            ->expectsOutputToContain('1:30 / 5:54')
            ->assertExitCode(0);
    });

    it('shows playing status icon when playing', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
                'progress_ms' => 30000,
                'duration_ms' => 180000,
                'is_playing' => true,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('Currently Playing:')
            ->assertExitCode(0);
    });

    it('shows paused status icon when not playing', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
                'progress_ms' => 30000,
                'duration_ms' => 180000,
                'is_playing' => false,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('Currently Playing:')
            ->assertExitCode(0);
    });

    it('handles nothing currently playing', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $this->artisan('current')
            ->expectsOutputToContain('Nothing is currently playing')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json option is provided with current playback', function (): void {
        $playbackData = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'progress_ms' => 60000,
            'duration_ms' => 240000,
            'is_playing' => true,
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($playbackData): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($playbackData);
        });

        $this->artisan('current', ['--json' => true])
            ->expectsOutputToContain(json_encode($playbackData))
            ->assertExitCode(0);
    });

    it('outputs JSON with no playback status when --json option is provided and nothing playing', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $expectedOutput = json_encode(['is_playing' => false, 'track' => null]);

        $this->artisan('current', ['--json' => true])
            ->expectsOutputToContain($expectedOutput)
            ->assertExitCode(0);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('current')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    it('handles zero duration gracefully', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Podcast Episode',
                'artist' => 'Podcast Host',
                'album' => 'Podcast Name',
                'progress_ms' => 0,
                'duration_ms' => 0,
                'is_playing' => true,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('Currently Playing:')
            ->expectsOutputToContain('Podcast Episode')
            ->expectsOutputToContain('0:00 / 0:00')
            ->expectsOutputToContain('0%')
            ->assertExitCode(0);
    });

    it('calculates progress percentage correctly at 50%', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Half Done Song',
                'artist' => 'Artist',
                'album' => 'Album',
                'progress_ms' => 100000, // 100 seconds
                'duration_ms' => 200000, // 200 seconds
                'is_playing' => true,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('50%')
            ->assertExitCode(0);
    });

    it('formats time correctly for songs over an hour', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Long Symphony',
                'artist' => 'Orchestra',
                'album' => 'Classical Album',
                'progress_ms' => 3720000, // 62 minutes (62:00)
                'duration_ms' => 5400000, // 90 minutes (90:00)
                'is_playing' => true,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('62:00 / 90:00')
            ->assertExitCode(0);
    });

    it('displays progress bar with correct fill at 100%', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Almost Done',
                'artist' => 'Artist',
                'album' => 'Album',
                'progress_ms' => 180000,
                'duration_ms' => 180000,
                'is_playing' => true,
            ]);
        });

        $this->artisan('current')
            ->expectsOutputToContain('100%')
            ->assertExitCode(0);
    });

});
