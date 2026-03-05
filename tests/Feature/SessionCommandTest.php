<?php

use App\Services\SpotifyService;

it('requires configuration', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('session', ['--mood' => 'flow'])
        ->assertFailed();
});

it('requires description or mood flag', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('session')
        ->assertFailed();
});

it('rejects invalid mood preset', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('session', ['--mood' => 'nonexistent'])
        ->assertFailed();
});

it('requires an active device', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getActiveDevice')->andReturn(null);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('session', ['--mood' => 'flow'])
        ->assertFailed();
});

it('runs a quick session with mood preset', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getActiveDevice')->andReturn(['id' => 'device-1', 'name' => 'Test']);
    $mock->shouldReceive('getSmartRecommendations')->andReturn([
        ['uri' => 'spotify:track:abc', 'name' => 'Track 1', 'artist' => 'Artist 1'],
        ['uri' => 'spotify:track:def', 'name' => 'Track 2', 'artist' => 'Artist 2'],
    ]);
    $mock->shouldReceive('addToQueue')->twice();
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('session', ['--mood' => 'chill', '--duration' => '10'])
        ->assertSuccessful();
});

it('has correct signature options', function () {
    $command = $this->app->make(\App\Commands\SessionCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('mood'))->toBeTrue();
    expect($definition->hasOption('duration'))->toBeTrue();
    expect($definition->hasOption('ai'))->toBeTrue();
    expect($definition->getOption('duration')->getDefault())->toBe('30');
});

it('has correct command metadata', function () {
    $command = $this->app->make(\App\Commands\SessionCommand::class);
    expect($command->getName())->toBe('session');
    expect($command->getDescription())->not->toBeEmpty();
});

it('accepts all valid mood presets', function () {
    $validMoods = ['chill', 'flow', 'hype', 'focus', 'party', 'upbeat', 'melancholy', 'ambient', 'workout', 'sleep'];

    foreach ($validMoods as $mood) {
        expect(array_key_exists($mood, config('autopilot.mood_presets')))->toBeTrue();
    }
});
