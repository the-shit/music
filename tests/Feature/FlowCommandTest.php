<?php

use App\Services\Daemon\Process;
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
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', null);
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
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', null);
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
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', null);
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

it('advertises --device on help', function (): void {
    $this->artisan('flow', ['--help' => true])
        ->expectsOutputToContain('--device')
        ->assertSuccessful();
});

it('plays on --device without a prior dummy play', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track', 'artist' => 'Ambient Artist', 'album' => 'Focus Album'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getDevices')->once()->andReturn([
        ['id' => 'thor-id', 'name' => 'Thor Speaker', 'is_active' => false],
    ]);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:1', 'thor-id');
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('flow', ['--device' => 'Thor', '--limit' => 1, '--json' => true])
        ->assertSuccessful();
});

it('fails json when --device is missing from Connect', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getDevices')->once()->andReturn([
        ['id' => 'other-id', 'name' => 'Kitchen', 'is_active' => false],
    ]);
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('flow', ['--device' => 'Thor', '--json' => true])
        ->expectsOutputToContain('Device \'Thor\' not found')
        ->assertFailed();
});

it('uses the local daemon device under --json when none is named', function (): void {
    DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');
    $this->mock(Process::class, function ($mock): void {
        $mock->shouldReceive('isAlive')->once()->andReturn(true);
    });

    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Focus Track', 'artist' => 'Ambient Artist', 'album' => 'Focus Album'],
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

    $this->artisan('flow', ['--limit' => 1, '--json' => true])
        ->assertSuccessful();
});

it('rejects Boom Boom Pow and pest by Kwite from flow gather', function (): void {
    $authMock = Mockery::mock(SpotifyAuthManager::class);
    $authMock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyAuthManager::class, $authMock);

    $discoveryMock = Mockery::mock(SpotifyDiscoveryService::class);
    $discoveryMock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:bbb', 'name' => 'Boom Boom Pow', 'artist' => 'Black Eyed Peas', 'album' => 'The E.N.D.'],
        ['uri' => 'spotify:track:pest', 'name' => 'pest', 'artist' => 'Kwite', 'album' => 'pest'],
        ['uri' => 'spotify:track:good', 'name' => 'Ambient Instrumentals', 'artist' => 'Focus Ensemble', 'album' => 'Deep Work'],
    ]);
    $discoveryMock->shouldReceive('getRecentlyPlayed')->once()->with(20)->andReturn([]);
    $this->app->instance(SpotifyDiscoveryService::class, $discoveryMock);

    $playerMock = Mockery::mock(SpotifyPlayerService::class);
    $playerMock->shouldReceive('getQueue')->once()->andReturn(['queue' => [], 'currently_playing' => null]);
    $playerMock->shouldReceive('play')->once()->with('spotify:track:good', null);
    $playerMock->shouldReceive('addToQueue')->never();
    $this->app->instance(SpotifyPlayerService::class, $playerMock);

    $this->artisan('flow', ['--limit' => 10, '--json' => true])
        ->expectsOutputToContain('Ambient Instrumentals')
        ->assertSuccessful();
});
