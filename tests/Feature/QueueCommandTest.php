<?php

use App\Services\SpotifyService;

describe('QueueCommand', function (): void {

    it('adds track to queue', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')
                ->once()
                ->with('test song')
                ->andReturn($searchResult);
            $mock->shouldReceive('addToQueue')
                ->once()
                ->with('spotify:track:123');
        });

        $this->artisan('queue', ['query' => 'test song'])
            ->expectsOutputToContain('🎵 Searching for: test song')
            ->expectsOutputToContain('➕ Added to queue: Test Song by Test Artist')
            ->expectsOutputToContain('📋 It will play after the current track')
            ->expectsOutputToContain('✅ Successfully added to queue!')
            ->assertExitCode(0);
    });

    it('handles no search results', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')
                ->once()
                ->with('nonexistent')
                ->andReturn(null);
        });

        $this->artisan('queue', ['query' => 'nonexistent'])
            ->expectsOutputToContain('🎵 Searching for: nonexistent')
            ->expectsOutputToContain('No results found for: nonexistent')
            ->assertExitCode(1);
    });

    it('handles API errors', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')
                ->once()
                ->with('test song')
                ->andReturn($searchResult);
            $mock->shouldReceive('addToQueue')
                ->once()
                ->with('spotify:track:123')
                ->andThrow(new Exception('No active device'));
        });

        $this->artisan('queue', ['query' => 'test song'])
            ->expectsOutputToContain('🎵 Searching for: test song')
            ->expectsOutputToContain('Failed to add to queue: No active device')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('queue', ['query' => 'test'])
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

});
