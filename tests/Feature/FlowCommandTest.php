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

it('fails when not configured', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('flow')
        ->assertFailed();
});
