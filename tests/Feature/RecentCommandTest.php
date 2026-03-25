<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;

it('outputs recently played as json', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->with(20)->andReturn([
        [
            'uri' => 'spotify:track:1',
            'name' => 'Recent Song',
            'artist' => 'Artist',
            'album' => 'Album',
            'played_at' => '2025-01-01T12:00:00Z',
        ],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('recent', ['--json' => true])
        ->assertSuccessful();
});

it('displays recently played in human format', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->andReturn([
        [
            'uri' => 'spotify:track:1',
            'name' => 'Recent Song',
            'artist' => 'Artist',
            'album' => 'Album',
            'played_at' => '2025-01-01T12:00:00Z',
        ],
    ]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('recent')
        ->expectsOutputToContain('Recent Song')
        ->assertSuccessful();
});

it('handles empty recently played', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('recent')
        ->expectsOutputToContain('No recently played')
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $this->artisan('recent')
        ->assertFailed();
});
