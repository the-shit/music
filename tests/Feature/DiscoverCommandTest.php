<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;

it('outputs discovery results as json', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->andReturn([
        ['uri' => 'spotify:track:r1', 'name' => 'Recent', 'artist' => 'SeedArtist', 'album' => 'A', 'played_at' => null],
    ]);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Found Track', 'artist' => 'Found Artist', 'album' => 'Found Album'],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('discover', ['query' => 'ambient electronic', '--json' => true])
        ->assertSuccessful();
});

it('displays discovery results in human format', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->andReturn([]);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Found Track', 'artist' => 'Found Artist', 'album' => 'Found Album'],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('discover', ['query' => 'ambient'])
        ->expectsOutputToContain('Found Track')
        ->assertSuccessful();
});

it('handles no results', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->andReturn([]);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('discover', ['query' => 'xyznonexistent'])
        ->expectsOutputToContain('No tracks found')
        ->assertSuccessful();
});

it('seeds from top artists when requested', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getTopArtists')->with('short_term', 3)->andReturn([
        ['name' => 'TopArtist', 'genres' => ['rock'], 'uri' => 'spotify:artist:1'],
    ]);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('discover', ['query' => 'rock', '--seed-from' => 'top', '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $this->artisan('discover', ['query' => 'test'])
        ->assertFailed();
});
