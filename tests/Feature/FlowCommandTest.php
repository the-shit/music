<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;

it('queues flow tracks and outputs json', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track', 'artist' => 'Ambient Artist', 'album' => 'Focus Album'],
        ['uri' => 'spotify:track:2', 'name' => 'Study Beat', 'artist' => 'Lofi Producer', 'album' => 'Study Album'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1');
    $playerMock->shouldReceive('addToQueue')->once()->with('spotify:track:2');
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('flow', ['--json' => true])
        ->assertSuccessful();
});

it('displays flow tracks in human format', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once();
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('flow')
        ->expectsOutputToContain('Flow mode activated')
        ->assertSuccessful();
});

it('fails when no tracks found', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('flow')
        ->assertFailed();
});

it('respects custom limit option overriding duration', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track 1', 'artist' => 'Artist 1', 'album' => 'Album 1'],
        ['uri' => 'spotify:track:2', 'name' => 'Focus Track 2', 'artist' => 'Artist 2', 'album' => 'Album 2'],
        ['uri' => 'spotify:track:3', 'name' => 'Focus Track 3', 'artist' => 'Artist 3', 'album' => 'Album 3'],
        ['uri' => 'spotify:track:4', 'name' => 'Focus Track 4', 'artist' => 'Artist 4', 'album' => 'Album 4'],
        ['uri' => 'spotify:track:5', 'name' => 'Focus Track 5', 'artist' => 'Artist 5', 'album' => 'Album 5'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once();
    $playerMock->shouldReceive('addToQueue')->times(4);
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    // --limit=5 should override the default --duration=60 which would yield 18 tracks
    $this->artisan('flow', ['--limit' => 5, '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $this->artisan('flow')
        ->assertFailed();
});
