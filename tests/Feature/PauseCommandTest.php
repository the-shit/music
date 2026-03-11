<?php

use App\Services\SpotifyService;

describe('PauseCommand', function (): void {

    it('pauses playback', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'progress_ms' => 90000,
            ]);
            $mock->shouldReceive('pause')->once();
        });

        $this->artisan('pause')
            ->expectsOutputToContain('⏸️  Pausing Spotify playback...')
            ->expectsOutputToContain('✅ Playback paused!')
            ->assertExitCode(0);
    });

    it('handles API errors', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
            $mock->shouldReceive('pause')
                ->once()
                ->andThrow(new Exception('Already paused'));
        });

        $this->artisan('pause')
            ->expectsOutputToContain('⏸️  Pausing Spotify playback...')
            ->expectsOutputToContain('❌ Failed to pause: Already paused')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('pause')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')

            ->assertExitCode(1);
    });

});
