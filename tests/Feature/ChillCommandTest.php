<?php

use App\Services\SpotifyService;

it('queues chill tracks and outputs json', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Chill Track', 'artist' => 'Chill Artist', 'album' => 'Chill Album'],
        ['uri' => 'spotify:track:2', 'name' => 'Lofi Beat', 'artist' => 'Lofi Producer', 'album' => 'Lofi Album'],
    ]);
    $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $mock->shouldReceive('play')->once()->with('spotify:track:1');
    $mock->shouldReceive('addToQueue')->once()->with('spotify:track:2');
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('chill', ['--json' => true])
        ->assertSuccessful();
});

it('displays chill mode message', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Chill Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $mock->shouldReceive('play')->once();
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('chill')
        ->expectsOutputToContain('Chill mode activated')
        ->assertSuccessful();
});

it('fails when no tracks found', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('chill')
        ->assertFailed();
});

it('respects custom limit option', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Chill Track 1', 'artist' => 'Artist 1', 'album' => 'Album 1'],
        ['uri' => 'spotify:track:2', 'name' => 'Chill Track 2', 'artist' => 'Artist 2', 'album' => 'Album 2'],
        ['uri' => 'spotify:track:3', 'name' => 'Chill Track 3', 'artist' => 'Artist 3', 'album' => 'Album 3'],
    ]);
    $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $mock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $mock->shouldReceive('play')->once();
    $mock->shouldReceive('addToQueue')->twice();
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('chill', ['--limit' => 3, '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('chill')
        ->assertFailed();
});
