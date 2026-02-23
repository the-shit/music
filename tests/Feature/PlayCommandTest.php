<?php

use App\Services\SpotifyService;

describe('PlayCommand', function () {

    it('searches and plays a track', function () {
        $searchResult = [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')
                ->once()
                ->with('test song')
                ->andReturn($searchResult);
            $mock->shouldReceive('play')
                ->once()
                ->with('spotify:track:123', null);
        });

        $this->artisan('play', ['query' => 'test song'])
            ->expectsOutput('🎵 Searching for: test song')
            ->expectsOutput('▶️  Playing: Test Song by Test Artist')
            ->expectsOutput('✅ Playback started!')
            ->assertExitCode(0);
    });

    it('handles no search results', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')
                ->once()
                ->with('nonexistent song')
                ->andReturn(null);
        });

        $this->artisan('play', ['query' => 'nonexistent song'])
            ->expectsOutput('🎵 Searching for: nonexistent song')
            ->expectsOutput('No results found for: nonexistent song')
            ->assertExitCode(1);
    });

    it('handles API errors gracefully', function () {
        $searchResult = [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')
                ->once()
                ->with('test song')
                ->andReturn($searchResult);
            $mock->shouldReceive('play')
                ->once()
                ->with('spotify:track:123', null)
                ->andThrow(new Exception('No active device'));
        });

        $this->artisan('play', ['query' => 'test song'])
            ->expectsOutput('🎵 Searching for: test song')
            ->expectsOutput('Failed to play: No active device')
            ->assertExitCode(1);
    });

    it('requires configuration', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('play', ['query' => 'test'])
            ->expectsOutput('❌ Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

    it('plays with --json flag suppresses normal output', function () {
        $searchResult = [
            'uri' => 'spotify:track:abc',
            'name' => 'JSON Song',
            'artist' => 'JSON Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
            $mock->shouldReceive('play')->once()->with('spotify:track:abc', null);
        });

        $this->artisan('play', ['query' => 'test', '--json' => true])
            ->doesntExpectOutput('🎵 Searching for: test')
            ->doesntExpectOutput('▶️  Playing: JSON Song by JSON Artist')
            ->assertExitCode(0);
    });

    it('adds to queue with --queue flag', function () {
        $searchResult = [
            'uri' => 'spotify:track:456',
            'name' => 'Queue Song',
            'artist' => 'Queue Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:456');
        });

        $this->artisan('play', ['query' => 'test', '--queue' => true])
            ->expectsOutputToContain('Added to queue: Queue Song by Queue Artist')
            ->assertExitCode(0);
    });

    it('adds to queue with --queue and --json flags', function () {
        $searchResult = [
            'uri' => 'spotify:track:789',
            'name' => 'Queue JSON Song',
            'artist' => 'Queue Artist',
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:789');
        });

        $this->artisan('play', ['query' => 'test', '--queue' => true, '--json' => true])
            ->assertExitCode(0);
    });

    it('outputs json on no search results with --json flag', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')->once()->andReturn(null);
        });

        $this->artisan('play', ['query' => 'nothing', '--json' => true])
            ->assertExitCode(1);
    });

    it('outputs json error on API exception with --json flag', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')->once()->andThrow(new Exception('API down'));
        });

        $this->artisan('play', ['query' => 'test', '--json' => true])
            ->assertExitCode(1);
    });

    it('finds device by name and plays on it', function () {
        $searchResult = [
            'uri' => 'spotify:track:device1',
            'name' => 'Device Song',
            'artist' => 'Artist',
        ];

        $devices = [
            ['id' => 'device-id-123', 'name' => 'Living Room Speaker'],
        ];

        $this->mock(SpotifyService::class, function ($mock) use ($searchResult, $devices) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getDevices')->once()->andReturn($devices);
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
            $mock->shouldReceive('play')->once()->with('spotify:track:device1', 'device-id-123');
        });

        $this->artisan('play', ['query' => 'test', '--device' => 'Living Room'])
            ->expectsOutputToContain('Using device: Living Room Speaker')
            ->assertExitCode(0);
    });

    it('fails when specified device is not found', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-1', 'name' => 'Kitchen'],
            ]);
        });

        $this->artisan('play', ['query' => 'test', '--device' => 'nonexistent'])
            ->expectsOutputToContain("Device 'nonexistent' not found")
            ->assertExitCode(1);
    });

});
