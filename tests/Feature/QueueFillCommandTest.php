<?php

use App\Services\SpotifyService;

describe('QueueFillCommand', function () {

    it('fills queue with recommendations based on current track', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'artists' => [['id' => 'artist456']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->with(['track123'], ['artist456'], 10)
                ->andReturn([
                    ['uri' => 'spotify:track:rec1', 'name' => 'Rec One', 'artist' => 'Artist A'],
                    ['uri' => 'spotify:track:rec2', 'name' => 'Rec Two', 'artist' => 'Artist B'],
                ]);
            $mock->shouldReceive('addToQueue')->twice();
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Queued: Rec One by Artist A')
            ->expectsOutputToContain('Queued: Rec Two by Artist B')
            ->expectsOutputToContain('Queue: 2/5')
            ->assertExitCode(0);
    });

    it('skips duplicates already in queue', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'artists' => [['id' => 'artist456']],
                ],
                'queue' => [
                    ['uri' => 'spotify:track:existing1'],
                ],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([
                    ['uri' => 'spotify:track:existing1', 'name' => 'Existing', 'artist' => 'Dup'],
                    ['uri' => 'spotify:track:track123', 'name' => 'Currently Playing', 'artist' => 'Dup'],
                    ['uri' => 'spotify:track:new1', 'name' => 'New Track', 'artist' => 'Fresh'],
                ]);
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:new1');
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Queued: New Track by Fresh')
            ->assertExitCode(0);
    });

    it('skips recently played tracks', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'artists' => [['id' => 'artist456']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([
                ['uri' => 'spotify:track:old1'],
                ['uri' => 'spotify:track:old2'],
            ]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([
                    ['uri' => 'spotify:track:old1', 'name' => 'Old One', 'artist' => 'Past'],
                    ['uri' => 'spotify:track:old2', 'name' => 'Old Two', 'artist' => 'Past'],
                    ['uri' => 'spotify:track:fresh1', 'name' => 'Fresh Track', 'artist' => 'New'],
                ]);
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:fresh1');
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Queued: Fresh Track by New')
            ->assertExitCode(0);
    });

    it('does nothing when queue is already at target', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['uri' => 'a'], ['uri' => 'b'], ['uri' => 'c'],
                    ['uri' => 'd'], ['uri' => 'e'],
                ],
            ]);
        });

        $this->artisan('queue:fill')
            ->assertExitCode(0);
    });

    it('warns when no recommendations available and no current track for fallback', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([]);
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('No recommendations available')
            ->assertExitCode(0);
    });

    it('falls back to search when recommendations API returns empty', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'name' => 'Test Song',
                    'artists' => [['id' => 'artist456', 'name' => 'Test Artist']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->with(['track123'], ['artist456'], 10)
                ->andReturn([]);
            $mock->shouldReceive('getRelatedTracks')
                ->once()
                ->with('Test Artist', 'Test Song', 10)
                ->andReturn([
                    ['uri' => 'spotify:track:search1', 'name' => 'Search Result 1', 'artist' => 'Test Artist'],
                    ['uri' => 'spotify:track:search2', 'name' => 'Search Result 2', 'artist' => 'Test Artist'],
                ]);
            $mock->shouldReceive('addToQueue')->twice();
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Queued: Search Result 1 by Test Artist')
            ->expectsOutputToContain('Queued: Search Result 2 by Test Artist')
            ->expectsOutputToContain('Queue: 2/5')
            ->assertExitCode(0);
    });

    it('deduplicates search fallback results against queue and recently played', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'name' => 'Test Song',
                    'artists' => [['id' => 'artist456', 'name' => 'Test Artist']],
                ],
                'queue' => [
                    ['uri' => 'spotify:track:queued1'],
                ],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([
                ['uri' => 'spotify:track:recent1'],
            ]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([]);
            $mock->shouldReceive('getRelatedTracks')
                ->once()
                ->with('Test Artist', 'Test Song', 9)
                ->andReturn([
                    ['uri' => 'spotify:track:track123', 'name' => 'Test Song', 'artist' => 'Test Artist'],
                    ['uri' => 'spotify:track:queued1', 'name' => 'Queued', 'artist' => 'Test Artist'],
                    ['uri' => 'spotify:track:recent1', 'name' => 'Recent', 'artist' => 'Test Artist'],
                    ['uri' => 'spotify:track:fresh1', 'name' => 'Fresh One', 'artist' => 'Test Artist'],
                ]);
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:fresh1');
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Queued: Fresh One by Test Artist')
            ->assertExitCode(0);
    });

    it('warns when both recommendations and search fallback return empty', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'name' => 'Test Song',
                    'artists' => [['id' => 'artist456', 'name' => 'Test Artist']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([]);
            $mock->shouldReceive('getRelatedTracks')
                ->once()
                ->with('Test Artist', 'Test Song', 10)
                ->andReturn([]);
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('No recommendations available')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is used', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'artists' => [['id' => 'artist456']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([
                    ['uri' => 'spotify:track:rec1', 'name' => 'Rec', 'artist' => 'Art'],
                ]);
            $mock->shouldReceive('addToQueue')->once();
        });

        $this->artisan('queue:fill', ['--json' => true])
            ->expectsOutputToContain('"filled":true')
            ->assertExitCode(0);
    });

    it('outputs JSON when queue already full', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['uri' => 'a'], ['uri' => 'b'], ['uri' => 'c'],
                    ['uri' => 'd'], ['uri' => 'e'],
                ],
            ]);
        });

        $this->artisan('queue:fill', ['--json' => true])
            ->expectsOutputToContain('"filled":false')
            ->assertExitCode(0);
    });

    it('respects custom target size', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'artists' => [['id' => 'artist456']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->with(['track123'], ['artist456'], 8)
                ->andReturn([
                    ['uri' => 'spotify:track:r1', 'name' => 'R1', 'artist' => 'A1'],
                    ['uri' => 'spotify:track:r2', 'name' => 'R2', 'artist' => 'A2'],
                    ['uri' => 'spotify:track:r3', 'name' => 'R3', 'artist' => 'A3'],
                ]);
            $mock->shouldReceive('addToQueue')->times(3);
        });

        $this->artisan('queue:fill', ['--target' => 3])
            ->expectsOutputToContain('Queue: 3/3')
            ->assertExitCode(0);
    });

    it('handles API errors gracefully', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')
                ->once()
                ->andThrow(new Exception('Network error'));
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Failed to fill queue: Network error')
            ->assertExitCode(1);
    });

    it('requires configuration', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('queue:fill')
            ->expectsOutput('❌ Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

    it('continues when individual queue additions fail', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'id' => 'track123',
                    'uri' => 'spotify:track:track123',
                    'artists' => [['id' => 'artist456']],
                ],
                'queue' => [],
            ]);
            $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
            $mock->shouldReceive('getRecommendations')
                ->once()
                ->andReturn([
                    ['uri' => 'spotify:track:r1', 'name' => 'R1', 'artist' => 'A1'],
                    ['uri' => 'spotify:track:r2', 'name' => 'R2', 'artist' => 'A2'],
                    ['uri' => 'spotify:track:r3', 'name' => 'R3', 'artist' => 'A3'],
                ]);
            $mock->shouldReceive('addToQueue')
                ->with('spotify:track:r1')
                ->andThrow(new Exception('Device busy'));
            $mock->shouldReceive('addToQueue')
                ->with('spotify:track:r2');
            $mock->shouldReceive('addToQueue')
                ->with('spotify:track:r3');
        });

        $this->artisan('queue:fill')
            ->expectsOutputToContain('Queued: R2 by A2')
            ->assertExitCode(0);
    });

});
