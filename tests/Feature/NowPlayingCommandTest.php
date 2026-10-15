<?php

use App\Commands\NowPlayingCommand;
use App\Services\SpotifyAuthManager;

describe('NowPlayingCommand', function (): void {

    describe('command metadata', function (): void {

        it('has correct command name', function (): void {
            $command = $this->app->make(NowPlayingCommand::class);
            expect($command->getName())->toBe('nowplaying');
        });

        it('has a description pointing at the media bridge', function (): void {
            $command = $this->app->make(NowPlayingCommand::class);
            expect($command->getDescription())->toContain('setup:media-bridge');
        });

        it('has --interval option with default of 3', function (): void {
            $command = $this->app->make(NowPlayingCommand::class);
            $definition = $command->getDefinition();
            expect($definition->hasOption('interval'))->toBeTrue();
            expect($definition->getOption('interval')->getDefault())->toBe('3');
        });

        it('has --stop option', function (): void {
            $command = $this->app->make(NowPlayingCommand::class);
            $definition = $command->getDefinition();
            expect($definition->hasOption('stop'))->toBeTrue();
        });

    });

    describe('--stop flag', function (): void {

        it('delegates uninstall and reports success', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                // --stop does not call ensureConfigured
            });

            $this->artisan('nowplaying', ['--stop' => true])
                ->expectsOutputToContain('Media bridge')
                ->assertExitCode(0);
        });

    });

    describe('not configured guard', function (): void {

        it('fails when credentials are missing', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(false);
                $mock->shouldReceive('hasCredentials')->once()->andReturn(false);
            });

            $this->artisan('nowplaying')
                ->expectsOutputToContain('Spotify is not configured')
                ->expectsOutputToContain('Run "spotify setup" first')
                ->assertExitCode(1);
        });

        it('fails when credentials exist but login is missing', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(false);
                $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
            });

            $this->artisan('nowplaying')
                ->expectsOutputToContain('Not authenticated with Spotify')
                ->expectsOutputToContain('Run "spotify login" first')
                ->assertExitCode(1);
        });

    });

    describe('deprecated redirect', function (): void {

        it('points users at setup:media-bridge when configured', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });

            $artisan = $this->artisan('nowplaying');

            if (PHP_OS_FAMILY === 'Darwin') {
                $artisan
                    ->expectsOutputToContain('deprecated')
                    ->expectsOutputToContain('setup:media-bridge')
                    ->assertExitCode(1);
            } else {
                $artisan
                    ->expectsOutputToContain('only supported on macOS')
                    ->assertExitCode(1);
            }
        });

    });

});
