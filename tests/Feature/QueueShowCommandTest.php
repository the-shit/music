<?php

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Artisan;

describe('QueueShowCommand', function () {

    it('requires Spotify to be configured', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('queue:show')
            ->expectsOutput('❌ Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

    it('shows empty queue message when queue is empty', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Queue is empty')
            ->assertExitCode(0);
    });

    it('shows the currently playing track when present', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'name' => 'Stairway to Heaven',
                    'artists' => [['name' => 'Led Zeppelin']],
                ],
                'queue' => [
                    ['name' => 'Black Dog', 'artists' => [['name' => 'Led Zeppelin']]],
                ],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Now: Stairway to Heaven by Led Zeppelin')
            ->expectsOutputToContain('Up Next')
            ->assertExitCode(0);
    });

    it('lists queued tracks with numbers', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['name' => 'First Track', 'artists' => [['name' => 'Artist A']]],
                    ['name' => 'Second Track', 'artists' => [['name' => 'Artist B']]],
                    ['name' => 'Third Track', 'artists' => [['name' => 'Artist C']]],
                ],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('First Track')
            ->expectsOutputToContain('Second Track')
            ->expectsOutputToContain('Third Track')
            ->assertExitCode(0);
    });

    it('uses Unknown for tracks with missing artist', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['name' => 'Mystery', 'artists' => []],
                ],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Unknown')
            ->assertExitCode(0);
    });

    it('shows total track count', function () {
        $queue = [];
        for ($i = 1; $i <= 5; $i++) {
            $queue[] = ['name' => "Track {$i}", 'artists' => [['name' => 'Artist']]];
        }

        $this->mock(SpotifyService::class, function ($mock) use ($queue) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => $queue,
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('5 tracks queued')
            ->assertExitCode(0);
    });

    it('indicates when more than 20 tracks exist', function () {
        $queue = [];
        for ($i = 1; $i <= 25; $i++) {
            $queue[] = ['name' => "Track {$i}", 'artists' => [['name' => 'Artist']]];
        }

        $this->mock(SpotifyService::class, function ($mock) use ($queue) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => $queue,
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('5 more')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'name' => 'Now Song',
                    'artists' => [['name' => 'Now Artist']],
                ],
                'queue' => [
                    ['name' => 'Next Song', 'artists' => [['name' => 'Next Artist']]],
                ],
            ]);
        });

        Artisan::call('queue:show', ['--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"currently_playing"')
            ->toContain('Now Song')
            ->toContain('"total":1');
    });

    it('outputs JSON with null currently_playing when nothing is playing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [],
            ]);
        });

        $this->artisan('queue:show', ['--json' => true])
            ->expectsOutputToContain('"currently_playing":null')
            ->assertExitCode(0);
    });

    it('handles API errors gracefully', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('getQueue')
                ->once()
                ->andThrow(new Exception('Network timeout'));
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Failed to get queue: Network timeout')
            ->assertExitCode(1);
    });

    it('has the correct command name', function () {
        $command = $this->app->make(\App\Commands\QueueShowCommand::class);
        expect($command->getName())->toBe('queue:show');
    });

    it('has a description', function () {
        $command = $this->app->make(\App\Commands\QueueShowCommand::class);
        expect($command->getDescription())->not->toBeEmpty();
    });

});
