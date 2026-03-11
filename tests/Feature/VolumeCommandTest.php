<?php

use App\Services\SpotifyService;

describe('VolumeCommand', function (): void {

    it('shows current volume when no argument', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'device' => [
                'name' => 'Test Device',
                'volume_percent' => 42,
            ],
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
        });

        $this->artisan('volume')
            ->expectsOutputToContain('🔊 Current volume: 42%')
            ->assertExitCode(0);
    });

    it('sets volume to specific level', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('setVolume')->once()->with(75)->andReturn(true);
        });

        $this->artisan('volume', ['level' => '75'])
            ->expectsOutputToContain('🔊 Volume set to 75%')
            ->assertExitCode(0);
    });

    it('handles zero volume correctly', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('setVolume')->once()->with(0)->andReturn(true);
        });

        $this->artisan('volume', ['level' => '0'])
            ->expectsOutputToContain('🔇 Volume set to 0%')
            ->assertExitCode(0);
    });

    it('clamps values above 100', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('setVolume')->once()->with(100)->andReturn(true);
        });

        $this->artisan('volume', ['level' => '150'])
            ->expectsOutputToContain('🔊 Volume set to 100%')
            ->assertExitCode(0);
    });

    it('handles relative negative values', function (): void {
        // -10 is treated as relative change (current - 10)
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'device' => ['volume_percent' => 50],
            ]);
            $mock->shouldReceive('setVolume')->once()->with(40)->andReturn(true);
        });

        $this->artisan('volume', ['level' => '-10'])
            ->assertExitCode(0);
    });

    it('handles API failure', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('setVolume')->once()->with(50)->andReturn(false);
        });

        $this->artisan('volume', ['level' => '50'])
            ->expectsOutputToContain('❌ Failed to set volume')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('volume')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    it('handles no active device', function (): void {
        $currentPlayback = [
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'device' => null,
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($currentPlayback): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn($currentPlayback);
        });

        $this->artisan('volume')
            ->expectsOutputToContain('❌ No active device found')
            ->assertExitCode(1);
    });

});
