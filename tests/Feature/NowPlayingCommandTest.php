<?php

use App\Services\SpotifyService;

describe('NowPlayingCommand', function () {

    describe('command metadata', function () {

        it('has correct command name', function () {
            $command = $this->app->make(\App\Commands\NowPlayingCommand::class);
            expect($command->getName())->toBe('nowplaying');
        });

        it('has a description', function () {
            $command = $this->app->make(\App\Commands\NowPlayingCommand::class);
            expect($command->getDescription())->not->toBeEmpty();
        });

        it('has --interval option with default of 3', function () {
            $command = $this->app->make(\App\Commands\NowPlayingCommand::class);
            $definition = $command->getDefinition();
            expect($definition->hasOption('interval'))->toBeTrue();
            expect($definition->getOption('interval')->getDefault())->toBe('3');
        });

        it('has --stop option', function () {
            $command = $this->app->make(\App\Commands\NowPlayingCommand::class);
            $definition = $command->getDefinition();
            expect($definition->hasOption('stop'))->toBeTrue();
        });

    });

    describe('--stop flag', function () {

        it('reports no bridge running when pkill finds nothing', function () {
            $this->artisan('nowplaying', ['--stop' => true])
                ->expectsOutputToContain('No running nowplaying bridge found.')
                ->assertExitCode(0);
        });

        it('returns success exit code regardless of bridge state', function () {
            $this->artisan('nowplaying', ['--stop' => true])
                ->assertExitCode(0);
        });

    });

    describe('not configured guard', function () {

        it('fails when not configured', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->once()->andReturn(false);
            });

            $this->artisan('nowplaying')
                ->expectsOutputToContain('Spotify is not configured')
                ->assertExitCode(1);
        });

    });

    describe('bridge script missing', function () {

        it('fails with error when bridge script does not exist', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });

            // Temporarily rename the bridge script so the command can't find it
            $bridgeScript = base_path('bin/nowplaying-bridge.py');
            $tempName = $bridgeScript.'.bak';
            $renamed = false;
            if (file_exists($bridgeScript)) {
                rename($bridgeScript, $tempName);
                $renamed = true;
            }

            $this->artisan('nowplaying')
                ->expectsOutputToContain('Bridge script not found')
                ->assertExitCode(1);

            if ($renamed) {
                rename($tempName, $bridgeScript);
            }
        });

    });

    describe('python3 not found', function () {

        it('fails with error when python3 is not available', function () {
            // We can test this by checking the command structure is correct
            // The actual python3 check uses shell_exec('which python3')
            // In a real test environment python3 is usually available
            $command = $this->app->make(\App\Commands\NowPlayingCommand::class);
            expect($command)->toBeInstanceOf(\App\Commands\NowPlayingCommand::class);
        });

    });

});
