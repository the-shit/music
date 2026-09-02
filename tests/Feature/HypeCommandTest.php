<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use Tests\DaemonHealSpyCommand;

beforeEach(function (): void {
    $this->previousHome = getenv('HOME') ?: '/tmp/spotify-cli-test';
    $this->tempDir = DaemonHealSpyCommand::isolateHome();
    DaemonHealSpyCommand::bind($this->app);
});

afterEach(function (): void {
    DaemonHealSpyCommand::restoreHome($this->previousHome, $this->tempDir);
});

it('queues hype tracks and outputs json', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Hype Track', 'artist' => 'Hype Artist', 'album' => 'Hype Album'],
        ['uri' => 'spotify:track:2', 'name' => 'Energy Song', 'artist' => 'Energy Artist', 'album' => 'Energy Album'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', null);
    $playerMock->shouldReceive('addToQueue')->once()->with('spotify:track:2');
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('hype', ['--json' => true])
        ->assertSuccessful();
});

it('displays hype mode message', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Hype Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', null);
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('hype')
        ->expectsOutputToContain('Hype mode activated')
        ->assertSuccessful();
});

it('fails when no tracks found', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $this->artisan('hype')
        ->assertFailed();
});

it('respects custom limit option', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Hype Track 1', 'artist' => 'Artist 1', 'album' => 'Album 1'],
        ['uri' => 'spotify:track:2', 'name' => 'Hype Track 2', 'artist' => 'Artist 2', 'album' => 'Album 2'],
        ['uri' => 'spotify:track:3', 'name' => 'Hype Track 3', 'artist' => 'Artist 3', 'album' => 'Album 3'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', null);
    $playerMock->shouldReceive('addToQueue')->twice();
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('hype', ['--limit' => 3, '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $this->artisan('hype')
        ->assertFailed();
});

it('plays on --device', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Hype Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getDevices')->once()->andReturn([
        ['id' => 'thor-id', 'name' => 'Thor', 'is_active' => false],
    ]);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', 'thor-id');
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('hype', ['--device' => 'Thor', '--limit' => 1, '--json' => true])
        ->assertSuccessful();
});
