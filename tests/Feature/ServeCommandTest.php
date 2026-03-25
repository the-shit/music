<?php

use App\Services\SpotifyAuthManager;

it('requires configuration', function (): void {
    $mock = Mockery::mock(SpotifyAuthManager::class);
    $mock->shouldReceive('isConfigured')->andReturn(false);
    $this->app->instance(SpotifyAuthManager::class, $mock);

    $this->artisan('serve')
        ->assertFailed();
});

it('has correct signature options', function (): void {
    $command = $this->app->make(\App\Commands\ServeCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('port'))->toBeTrue();
    expect($definition->hasOption('host'))->toBeTrue();
    expect($definition->getOption('port')->getDefault())->toBe('9876');
    expect($definition->getOption('host')->getDefault())->toBe('127.0.0.1');
});

describe('ServeCommand extended', function (): void {

    describe('command metadata', function (): void {

        it('has correct command name', function (): void {
            $command = $this->app->make(\App\Commands\ServeCommand::class);
            expect($command->getName())->toBe('serve');
        });

        it('has a description', function (): void {
            $command = $this->app->make(\App\Commands\ServeCommand::class);
            expect($command->getDescription())->not->toBeEmpty();
        });

    });

    describe('handler script generation', function (): void {

        it('creates handler script that reads credentials from file at runtime', function (): void {
            $configDir = sys_get_temp_dir().'/test-spotify-serve';
            config(['spotify.client_id' => 'test_client_id_123456789012345']);
            config(['spotify.client_secret' => 'test_secret_123456789012345']);
            config(['spotify.token_path' => sys_get_temp_dir().'/test-token.json']);
            config(['spotify.config_dir' => $configDir]);

            // Use reflection to call the private createHandler method
            $command = $this->app->make(\App\Commands\ServeCommand::class);
            $reflection = new ReflectionClass($command);

            // Set up the command's output and input (required for artisan commands)
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\NullOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            $method = $reflection->getMethod('createHandler');
            $method->setAccessible(true);
            $scriptPath = $method->invoke($command);

            expect(file_exists($scriptPath))->toBeTrue();
            $content = file_get_contents($scriptPath);
            // Credentials should NOT be embedded in the script
            expect($content)->not->toContain('test_client_id_123456789012345');
            expect($content)->not->toContain('test_secret_123456789012345');
            // Instead, the credentials file path should be substituted
            expect($content)->toContain($configDir.'/credentials.json');
            expect($content)->not->toContain('CREDENTIALS_PATH_PLACEHOLDER');
            expect($content)->not->toContain('TOKEN_PATH_PLACEHOLDER');
            // Should have getCredentials function for runtime loading
            expect($content)->toContain('function getCredentials()');

            unlink($scriptPath);
        });

        it('sets handler script permissions to 0600', function (): void {
            config(['spotify.token_path' => sys_get_temp_dir().'/test-token.json']);
            config(['spotify.config_dir' => sys_get_temp_dir().'/test-spotify-serve2']);

            $command = $this->app->make(\App\Commands\ServeCommand::class);
            $reflection = new ReflectionClass($command);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\NullOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            $method = $reflection->getMethod('createHandler');
            $method->setAccessible(true);
            $scriptPath = $method->invoke($command);

            $perms = substr(sprintf('%o', fileperms($scriptPath)), -4);
            expect($perms)->toBe('0600');

            unlink($scriptPath);
        });

        it('handler script contains health endpoint', function (): void {
            config(['spotify.token_path' => sys_get_temp_dir().'/test-token.json']);
            config(['spotify.config_dir' => sys_get_temp_dir().'/test-spotify-serve3']);

            $command = $this->app->make(\App\Commands\ServeCommand::class);
            $reflection = new ReflectionClass($command);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\NullOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            $method = $reflection->getMethod('createHandler');
            $method->setAccessible(true);
            $scriptPath = $method->invoke($command);

            $content = file_get_contents($scriptPath);
            expect($content)->toContain('/health');
            expect($content)->toContain('/slack/queue');
            expect($content)->toContain('/api/queue');
            expect($content)->toContain('/api/now');

            unlink($scriptPath);
        });

    });

    describe('security', function (): void {

        it('handler script includes slack verification token check', function (): void {
            config(['spotify.token_path' => sys_get_temp_dir().'/test-token.json']);
            config(['spotify.config_dir' => sys_get_temp_dir().'/test-spotify-serve-sec']);

            $command = $this->app->make(\App\Commands\ServeCommand::class);
            $reflection = new ReflectionClass($command);

            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\NullOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            $method = $reflection->getMethod('createHandler');
            $method->setAccessible(true);
            $scriptPath = $method->invoke($command);

            $content = file_get_contents($scriptPath);
            expect($content)->toContain('getSlackVerificationToken');
            expect($content)->toContain('hash_equals');

            unlink($scriptPath);
        });

    });

});
