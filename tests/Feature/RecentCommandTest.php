<?php

use App\Services\SpotifyService;

it('outputs recently played as json', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getRecentlyPlayed')->with(20)->andReturn([
        [
            'uri' => 'spotify:track:1',
            'name' => 'Recent Song',
            'artist' => 'Artist',
            'album' => 'Album',
            'played_at' => '2025-01-01T12:00:00Z',
        ],
    ]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('recent', ['--json' => true])
        ->assertSuccessful();
});

it('displays recently played in human format', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getRecentlyPlayed')->andReturn([
        [
            'uri' => 'spotify:track:1',
            'name' => 'Recent Song',
            'artist' => 'Artist',
            'album' => 'Album',
            'played_at' => '2025-01-01T12:00:00Z',
        ],
    ]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('recent')
        ->expectsOutputToContain('Recent Song')
        ->assertSuccessful();
});

it('handles empty recently played', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getRecentlyPlayed')->andReturn([]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('recent')
        ->expectsOutputToContain('No recently played')
        ->assertSuccessful();
});

it('fails when not configured', function (): void {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('recent')
        ->assertFailed();
});
