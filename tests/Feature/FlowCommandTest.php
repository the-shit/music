<?php

use App\Services\SpotifyService;

it('queues flow tracks and outputs json', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track', 'artist' => 'Ambient Artist', 'album' => 'Focus Album'],
        ['uri' => 'spotify:track:2', 'name' => 'Study Beat', 'artist' => 'Lofi Producer', 'album' => 'Study Album'],
    ]);
    $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $mock->shouldReceive('play')->once()->with('spotify:track:1');
    $mock->shouldReceive('addToQueue')->once()->with('spotify:track:2');
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('flow', ['--json' => true])
        ->assertSuccessful();
});

it('displays flow tracks in human format', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $mock->shouldReceive('play')->once();
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('flow')
        ->expectsOutputToContain('Flow mode activated')
        ->assertSuccessful();
});

it('fails when no tracks found', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('flow')
        ->assertFailed();
});

it('respects custom limit option overriding duration', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track 1', 'artist' => 'Artist 1', 'album' => 'Album 1'],
        ['uri' => 'spotify:track:2', 'name' => 'Focus Track 2', 'artist' => 'Artist 2', 'album' => 'Album 2'],
        ['uri' => 'spotify:track:3', 'name' => 'Focus Track 3', 'artist' => 'Artist 3', 'album' => 'Album 3'],
        ['uri' => 'spotify:track:4', 'name' => 'Focus Track 4', 'artist' => 'Artist 4', 'album' => 'Album 4'],
        ['uri' => 'spotify:track:5', 'name' => 'Focus Track 5', 'artist' => 'Artist 5', 'album' => 'Album 5'],
    ]);
    $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $mock->shouldReceive('play')->once();
    $mock->shouldReceive('addToQueue')->times(4);
    $this->app->instance(SpotifyService::class, $mock);

    // --limit=5 should override the default --duration=60 which would yield 18 tracks
    $this->artisan('flow', ['--limit' => 5, '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('flow')
        ->assertFailed();
});
