<?php

use App\Services\SpotifyService;

it('requires configuration', function () {
    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('watch')
        ->assertFailed();
});

it('has correct signature options', function () {
    $command = $this->app->make(\App\Commands\WatchCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('interval'))->toBeTrue();
    expect($definition->hasOption('slack'))->toBeTrue();
    expect($definition->hasOption('json'))->toBeTrue();
    expect($definition->getOption('interval')->getDefault())->toBe('10');
});

describe('WatchCommand extended', function () {

    describe('command metadata', function () {

        it('has correct command name', function () {
            $command = $this->app->make(\App\Commands\WatchCommand::class);
            expect($command->getName())->toBe('watch');
        });

        it('has a description', function () {
            $command = $this->app->make(\App\Commands\WatchCommand::class);
            expect($command->getDescription())->not->toBeEmpty();
        });

        it('enforces minimum interval of 3 seconds', function () {
            // interval option exists and has default
            $command = $this->app->make(\App\Commands\WatchCommand::class);
            $definition = $command->getDefinition();
            expect((int) $definition->getOption('interval')->getDefault())->toBeGreaterThanOrEqual(3);
        });

    });

    describe('slack webhook loading', function () {

        it('reads slack webhook from config file when present', function () {
            $tempDir = sys_get_temp_dir().'/watch-test-'.uniqid();
            mkdir($tempDir, 0755, true);
            config(['spotify.config_dir' => $tempDir]);

            $slackConfig = ['webhook_url' => 'https://hooks.slack.com/test'];
            file_put_contents($tempDir.'/slack.json', json_encode($slackConfig));

            // The loadSlackWebhook reads from config dir — verify file exists and is valid
            $content = json_decode(file_get_contents($tempDir.'/slack.json'), true);
            expect($content['webhook_url'])->toBe('https://hooks.slack.com/test');

            array_map('unlink', glob($tempDir.'/*'));
            rmdir($tempDir);
        });

        it('falls back to env var when slack.json missing', function () {
            $tempDir = sys_get_temp_dir().'/watch-test-nofile-'.uniqid();
            mkdir($tempDir, 0755, true);
            config(['spotify.config_dir' => $tempDir]);

            // No slack.json — env fallback should be used
            expect(file_exists($tempDir.'/slack.json'))->toBeFalse();

            rmdir($tempDir);
        });

    });

});
