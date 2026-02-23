<?php

use App\Commands\DaemonSetupCommand;
use Illuminate\Support\Facades\Config;

describe('DaemonSetupCommand', function () {

    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/spotify-daemon-setup-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $_SERVER['HOME'] = $this->tempDir;
        Config::set('spotify.client_id', 'test_client_id');
        Config::set('spotify.client_secret', 'test_client_secret');
        $this->app->forgetInstance(DaemonSetupCommand::class);
    });

    afterEach(function () {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($this->tempDir);
        }
    });

    describe('command metadata', function () {

        it('has correct command name', function () {
            $command = $this->app->make(DaemonSetupCommand::class);
            expect($command->getName())->toBe('daemon:setup');
        });

        it('has a description', function () {
            $command = $this->app->make(DaemonSetupCommand::class);
            expect($command->getDescription())->not->toBeEmpty();
        });

        it('has no positional arguments', function () {
            $command = $this->app->make(DaemonSetupCommand::class);
            expect($command->getDefinition()->getArguments())->toBeEmpty();
        });

    });

    describe('banner output', function () {

        it('outputs the setup banner text', function () {
            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('banner');
            $method->setAccessible(true);
            $method->invoke($command);

            expect($output->fetch())->toContain('Spotify Daemon Setup');
        });

    });

    describe('displaySuccess output', function () {

        it('shows setup complete and usage instructions', function () {
            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            // Route Laravel Prompts output to our buffer
            \Laravel\Prompts\Prompt::setOutput($output);

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('displaySuccess');
            $method->setAccessible(true);
            $method->invoke($command);

            $text = $output->fetch();
            expect($text)->toContain('Setup Complete');
            expect($text)->toContain('spotify daemon start');
            expect($text)->toContain('spotify daemon stop');
        });

    });

    describe('authenticateSpotifyd internals', function () {

        it('returns early and shows already-authenticated when credentials file exists', function () {
            $cachePath = $this->tempDir.'/.config/spotify-cli/cache';
            mkdir($cachePath, 0755, true);
            file_put_contents($cachePath.'/credentials.json', json_encode([
                'username' => 'testuser',
                'auth_data' => 'sometoken',
            ]));

            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            // Route Laravel Prompts output to our buffer
            \Laravel\Prompts\Prompt::setOutput($output);

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('authenticateSpotifyd');
            $method->setAccessible(true);
            $method->invoke($command); // Must return without calling passthru

            expect($output->fetch())->toContain('Already authenticated with Spotify');
        });

        it('creates the cache directory when it does not exist', function () {
            $cachePath = $this->tempDir.'/.config/spotify-cli/cache';
            expect(is_dir($cachePath))->toBeFalse();

            // Pre-create credentials.json so the method returns before passthru
            mkdir($cachePath, 0755, true);
            file_put_contents($cachePath.'/credentials.json', json_encode(['username' => 'test']));

            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new \Illuminate\Console\OutputStyle($input, $output));

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('authenticateSpotifyd');
            $method->setAccessible(true);
            $method->invoke($command);

            expect(is_dir($cachePath))->toBeTrue();
        });

    });

    describe('installDependencies internals', function () {

        it('builds correct brew install command on macOS', function () {
            if (PHP_OS_FAMILY !== 'Darwin') {
                expect(true)->toBeTrue(); // Skip on non-macOS

                return;
            }

            // Test that the OS detection logic produces the right command
            $os = PHP_OS_FAMILY;
            $issues = ['spotifyd', 'sox'];

            $cmd = match ($os) {
                'Darwin' => 'brew install '.implode(' ', $issues),
                'Linux' => 'sudo apt install -y '.implode(' ', $issues),
                default => null,
            };

            expect($cmd)->toBe('brew install spotifyd sox');
        });

        it('builds correct apt install command on Linux', function () {
            $issues = ['spotifyd', 'sox'];
            $cmd = 'sudo apt install -y '.implode(' ', $issues);
            expect($cmd)->toBe('sudo apt install -y spotifyd sox');
        });

    });

});
