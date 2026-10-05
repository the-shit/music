<?php

use App\Commands\DaemonSetupCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Config;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('DaemonSetupCommand', function (): void {

    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/spotify-daemon-setup-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $_SERVER['HOME'] = $this->tempDir;
        Config::set('spotify.client_id', 'test_client_id');
        Config::set('spotify.client_secret', 'test_client_secret');
        $this->app->forgetInstance(DaemonSetupCommand::class);
    });

    afterEach(function (): void {
        if (property_exists($this, 'tempDir') && $this->tempDir !== null && is_dir($this->tempDir)) {
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

    describe('command metadata', function (): void {

        it('has correct command name', function (): void {
            $command = $this->app->make(DaemonSetupCommand::class);
            expect($command->getName())->toBe('daemon:setup');
            expect($command->isHidden())->toBeTrue();
        });

        it('has a description', function (): void {
            $command = $this->app->make(DaemonSetupCommand::class);
            expect($command->getDescription())->not->toBeEmpty();
        });

        it('has no positional arguments', function (): void {
            $command = $this->app->make(DaemonSetupCommand::class);
            expect($command->getDefinition()->getArguments())->toBeEmpty();
        });

    });

    describe('banner output', function (): void {

        it('outputs the setup banner text', function (): void {
            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new ArrayInput([]);
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('banner');
            $method->setAccessible(true);
            $method->invoke($command);

            expect($output->fetch())->toContain('Spotify Daemon Setup');
        });

    });

    describe('displaySuccess output', function (): void {

        it('shows setup complete and usage instructions', function (): void {
            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new ArrayInput([]);
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));

            // Route Laravel Prompts output to our buffer
            Prompt::setOutput($output);

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

    describe('authenticateSpotifyd internals', function (): void {

        it('returns early and shows already-authenticated when oauth credentials exist', function (): void {
            $cachePath = $this->tempDir.'/.config/spotify-cli/cache/oauth';
            mkdir($cachePath, 0755, true);
            file_put_contents($cachePath.'/credentials.json', json_encode([
                'username' => 'testuser',
                'auth_data' => 'sometoken',
            ]));

            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new ArrayInput([]);
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));

            // Route Laravel Prompts output to our buffer
            Prompt::setOutput($output);

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('authenticateSpotifyd');
            $method->setAccessible(true);
            $method->invoke($command); // Must return without calling passthru

            expect($output->fetch())->toContain('Already authenticated with Spotify');
        });

        it('creates the cache directory when it does not exist', function (): void {
            $cachePath = $this->tempDir.'/.config/spotify-cli/cache';
            expect(is_dir($cachePath))->toBeFalse();

            mkdir($cachePath.'/oauth', 0755, true);
            file_put_contents($cachePath.'/oauth/credentials.json', json_encode(['username' => 'test']));

            $command = $this->app->make(DaemonSetupCommand::class);
            $input = new ArrayInput([]);
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('authenticateSpotifyd');
            $method->setAccessible(true);
            $method->invoke($command);

            expect(is_dir($cachePath))->toBeTrue();
        });

        it('does not treat a zeroconf leftover as authenticated', function (): void {
            $cachePath = $this->tempDir.'/.config/spotify-cli/cache';
            mkdir($cachePath.'/zeroconf', 0755, true);
            file_put_contents($cachePath.'/zeroconf/credentials.json', json_encode(['username' => 'leftover']));
            file_put_contents($cachePath.'/credentials.json', json_encode(['username' => 'old-cache']));

            $binary = $this->tempDir.'/.local/bin/spotifyd-rodio';
            mkdir(dirname($binary), 0755, true);
            file_put_contents($binary, "#!/bin/sh\nexit 0\n");
            chmod($binary, 0755);

            $seen = null;
            $command = $this->app->make(DaemonSetupCommand::class);
            $command->setAuthenticateRunner(function (string $spotifyd, string $cache) use (&$seen): void {
                $seen = [$spotifyd, $cache];
            });

            $input = new ArrayInput([]);
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));
            Prompt::setOutput($output);

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('authenticateSpotifyd');
            $method->setAccessible(true);
            $result = $method->invoke($command);

            $text = $output->fetch();
            expect($text)->not->toContain('Already authenticated');
            expect($text)->toContain('Authentication failed');
            expect($result)->toBe(1);
            expect($command->hasOauthCredentials())->toBeFalse();
            expect($command->oauthCredentialsPath())->toEndWith('/cache/oauth/credentials.json');
            expect($command->oauthCredentialsPath())->not->toContain('zeroconf');
            expect($seen)->toBe([$binary, $cachePath]);
        });

        it('succeeds only after oauth credentials are written', function (): void {
            $cachePath = $this->tempDir.'/.config/spotify-cli/cache';
            $binary = $this->tempDir.'/.local/bin/spotifyd-rodio';
            mkdir(dirname($binary), 0755, true);
            file_put_contents($binary, "#!/bin/sh\nexit 0\n");
            chmod($binary, 0755);

            $command = $this->app->make(DaemonSetupCommand::class);
            $command->setAuthenticateRunner(function (string $spotifyd, string $cache) use ($binary, $cachePath): void {
                expect($spotifyd)->toBe($binary);
                expect($cache)->toBe($cachePath);
                mkdir($cache.'/oauth', 0755, true);
                file_put_contents($cache.'/oauth/credentials.json', '{"username":"speaker"}');
            });

            $input = new ArrayInput([]);
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));
            Prompt::setOutput($output);

            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('authenticateSpotifyd');
            $method->setAccessible(true);
            $result = $method->invoke($command);

            expect($result)->toBeNull();
            expect($output->fetch())->toContain('Spotify authentication successful');
            expect($command->hasOauthCredentials())->toBeTrue();
        });

    });

    describe('linux package path', function (): void {

        it('points at pacman or omarchy and never apt', function (): void {
            $spotifyd = trim((string) shell_exec('which spotifyd 2>/dev/null'));
            $sox = trim((string) shell_exec('which play 2>/dev/null'));
            if (($spotifyd !== '' && $spotifyd !== '0') && ($sox !== '' && $sox !== '0')) {
                expect(true)->toBeTrue();

                return;
            }

            $this->artisan('daemon', ['action' => 'setup'])
                ->expectsConfirmation('Install missing dependencies now?', 'no')
                ->expectsOutputToContain('omarchy pkg add')
                ->expectsOutputToContain('pacman -S')
                ->doesntExpectOutputToContain('apt install')
                ->assertExitCode(1);
        });

    });

});
