<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

describe('ShuffleCommand', function (): void {

    it('toggles shuffle state', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'shuffle_state' => false,
            'repeat_state' => 'off',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setShuffle')->once()->with(true)->andReturn(true);
        });

        $this->artisan('shuffle')
            ->expectsOutputToContain('🔀 Shuffle enabled')
            ->assertExitCode(0);
    });

    it('enables shuffle when specified', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'shuffle_state' => false,
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setShuffle')->once()->with(true)->andReturn(true);
        });

        $this->artisan('shuffle', ['state' => 'on'])
            ->expectsOutputToContain('🔀 Shuffle enabled')
            ->assertExitCode(0);
    });

    it('disables shuffle when specified', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'shuffle_state' => true,
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setShuffle')->once()->with(false)->andReturn(true);
        });

        $this->artisan('shuffle', ['state' => 'off'])
            ->expectsOutputToContain('➡️  Shuffle disabled')
            ->assertExitCode(0);
    });

    it('handles invalid state', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'shuffle_state' => false,
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
        });

        $this->artisan('shuffle', ['state' => 'invalid'])
            ->expectsOutputToContain("❌ Failed to change shuffle: Invalid state: invalid. Use 'on', 'off', or 'toggle'")
            ->assertExitCode(1);
    });

    it('requires active playback', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $this->artisan('shuffle')
            ->expectsOutputToContain('⚠️  Nothing is currently playing')
            ->expectsOutputToContain('💡 Start playing something first')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('shuffle')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    it('outputs JSON when requested', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'shuffle_state' => false,
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setShuffle')->once()->with(true)->andReturn(true);
        });

        $this->artisan('shuffle', ['--json' => true])
            ->expectsOutputToContain('{"shuffle":true,"message":"Shuffle enabled"}')
            ->assertExitCode(0);
    });

});
