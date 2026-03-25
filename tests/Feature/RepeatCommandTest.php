<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

describe('RepeatCommand', function (): void {

    it('toggles through states', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'repeat_state' => 'off',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setRepeat')->once()->with('context')->andReturn(true);
        });

        $this->artisan('repeat')
            ->expectsOutputToContain('🔁 Repeat current context (album/playlist)')
            ->assertExitCode(0);
    });

    it('cycles from context to track', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'repeat_state' => 'context',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setRepeat')->once()->with('track')->andReturn(true);
        });

        $this->artisan('repeat')
            ->expectsOutputToContain('🔂 Repeat current track')
            ->assertExitCode(0);
    });

    it('cycles from track to off', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'repeat_state' => 'track',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setRepeat')->once()->with('off')->andReturn(true);
        });

        $this->artisan('repeat')
            ->expectsOutputToContain('➡️  Repeat disabled')
            ->assertExitCode(0);
    });

    it('sets specific state', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'repeat_state' => 'off',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setRepeat')->once()->with('track')->andReturn(true);
        });

        $this->artisan('repeat', ['state' => 'track'])
            ->expectsOutputToContain('🔂 Repeat current track')
            ->assertExitCode(0);
    });

    it('handles invalid state', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'repeat_state' => 'off',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
        });

        $this->artisan('repeat', ['state' => 'invalid'])
            ->expectsOutputToContain("❌ Failed to change repeat mode: Invalid state: invalid. Use 'off', 'track', 'context', or 'toggle'")
            ->assertExitCode(1);
    });

    it('requires active playback', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $this->artisan('repeat')
            ->expectsOutputToContain('⚠️  Nothing is currently playing')
            ->expectsOutputToContain('💡 Start playing something first')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('repeat')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    it('outputs JSON when requested', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'repeat_state' => 'off',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
            $mock->shouldReceive('setRepeat')->once()->with('context')->andReturn(true);
        });

        $this->artisan('repeat', ['--json' => true])
            ->expectsOutputToContain('{"repeat":"context","message":"Repeat current context (album\/playlist)"}')
            ->assertExitCode(0);
    });

});
