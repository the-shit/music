<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;

it('outputs top tracks as json', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getTopTracks')->with('medium_term', 20)->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Top Song', 'artist' => 'Artist 1', 'album' => 'Album 1'],
        ['uri' => 'spotify:track:2', 'name' => 'Second Song', 'artist' => 'Artist 2', 'album' => 'Album 2'],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('top', ['--json' => true])
        ->assertSuccessful();
});

it('outputs top artists as json', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getTopArtists')->with('medium_term', 20)->andReturn([
        ['name' => 'Artist 1', 'genres' => ['rock'], 'uri' => 'spotify:artist:1'],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('top', ['--type' => 'artists', '--json' => true])
        ->assertSuccessful();
});

it('displays top tracks in human format', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getTopTracks')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Top Song', 'artist' => 'Artist 1', 'album' => 'Album 1'],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('top')
        ->expectsOutputToContain('Top Song')
        ->assertSuccessful();
});

it('handles custom range and limit', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getTopTracks')->with('short_term', 5)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('top', ['--range' => 'short_term', '--limit' => '5', '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $this->artisan('top')
        ->assertFailed();
});
