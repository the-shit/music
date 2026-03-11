<?php

use App\Services\SpotifyService;

it('outputs discovery results as json', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getRecentlyPlayed')->andReturn([
        ['uri' => 'spotify:track:r1', 'name' => 'Recent', 'artist' => 'SeedArtist', 'album' => 'A', 'played_at' => null],
    ]);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Found Track', 'artist' => 'Found Artist', 'album' => 'Found Album'],
    ]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('discover', ['query' => 'ambient electronic', '--json' => true])
        ->assertSuccessful();
});

it('displays discovery results in human format', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getRecentlyPlayed')->andReturn([]);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Found Track', 'artist' => 'Found Artist', 'album' => 'Found Album'],
    ]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('discover', ['query' => 'ambient'])
        ->expectsOutputToContain('Found Track')
        ->assertSuccessful();
});

it('handles no results', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getRecentlyPlayed')->andReturn([]);
    $mock->shouldReceive('searchMultiple')->andReturn([]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('discover', ['query' => 'xyznonexistent'])
        ->expectsOutputToContain('No tracks found')
        ->assertSuccessful();
});

it('seeds from top artists when requested', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getTopArtists')->with('short_term', 3)->andReturn([
        ['name' => 'TopArtist', 'genres' => ['rock'], 'uri' => 'spotify:artist:1'],
    ]);
    $mock->shouldReceive('searchMultiple')->andReturn([
        ['uri' => 'spotify:track:1', 'name' => 'Track', 'artist' => 'Artist', 'album' => 'Album'],
    ]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('discover', ['query' => 'rock', '--seed-from' => 'top', '--json' => true])
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('discover', ['query' => 'test'])
        ->assertFailed();
});
