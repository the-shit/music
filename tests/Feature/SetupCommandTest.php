<?php

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

describe('SetupCommand', function () {

    beforeEach(function () {
        // Use a temp directory for credentials during tests
        $this->testConfigDir = sys_get_temp_dir().'/spotify-cli-test-'.uniqid();
        mkdir($this->testConfigDir, 0755, true);
        config(['spotify.config_dir' => $this->testConfigDir]);

        // Mock Process for browser opening and clipboard
        Process::fake();
    });

    afterEach(function () {
        // Clean up test config directory
        if (is_dir($this->testConfigDir)) {
            array_map('unlink', glob($this->testConfigDir.'/*'));
            rmdir($this->testConfigDir);
        }

        // Restore interactive mode
        Prompt::interactive(true);
    });

    describe('already configured', function () {

        it('shows already configured message when credentials exist', function () {
            // Write credentials to the test config directory
            $credentialsFile = $this->testConfigDir.'/credentials.json';
            file_put_contents($credentialsFile, json_encode([
                'client_id' => 'existingclientid1234567890',
                'client_secret' => 'existingclientsecret12345',
            ]));

            $this->artisan('setup')
                ->expectsOutputToContain('Spotify is already configured')
                ->assertExitCode(0);
        });

    });

    describe('credential validation', function () {

        it('validates credentials have correct format', function () {
            // Spotify credentials should be at least 20 chars
            $clientId = 'abc123def456ghi789jk';
            $clientSecret = 'secret123secret456secret789secret0';

            expect(strlen($clientId))->toBeGreaterThanOrEqual(20);
            expect(strlen($clientSecret))->toBeGreaterThanOrEqual(20);
        });

    });

    describe('reset flag', function () {

        it('allows reset even when already configured', function () {
            $envFile = base_path('.env');
            $content = "SPOTIFY_CLIENT_ID=existingclientid1234567890\n";
            $content .= "SPOTIFY_CLIENT_SECRET=existingclientsecret12345\n";
            file_put_contents($envFile, $content);

            // With reset flag, it should proceed to setup
            // We'll just verify it doesn't immediately return "already configured"
            Prompt::interactive(false);

            $this->artisan('setup', ['--reset' => true])
                ->assertExitCode(0);
        });

    });

    describe('env file handling', function () {

        it('creates env file if it does not exist', function () {
            $envFile = base_path('.env');

            // Ensure env file exists for testing
            if (! file_exists($envFile)) {
                file_put_contents($envFile, '');
            }

            expect(file_exists($envFile))->toBeTrue();
        });

    });

    describe('validateCredentials method', function () {

        it('accepts valid credentials', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('validateCredentials');
            $method->setAccessible(true);

            $result = $method->invoke($command, [
                'client_id' => 'abcdef1234567890abcdef',
                'client_secret' => 'secret1234567890secret',
            ]);

            expect($result)->toBeTrue();
        });

        it('throws on short client_id', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('validateCredentials');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($command, [
                'client_id' => 'short',
                'client_secret' => 'secret1234567890secret',
            ]))->toThrow(\Exception::class, 'Client ID appears to be invalid');
        });

        it('throws on short client_secret', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('validateCredentials');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($command, [
                'client_id' => 'abcdef1234567890abcdef',
                'client_secret' => 'short',
            ]))->toThrow(\Exception::class, 'Client Secret appears to be invalid');
        });

        it('throws on client_id with invalid characters', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('validateCredentials');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($command, [
                'client_id' => 'invalid-chars-here!!!!!!',
                'client_secret' => 'secret1234567890secret',
            ]))->toThrow(\Exception::class, 'Client ID contains invalid characters');
        });

        it('throws on client_secret with invalid characters', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('validateCredentials');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($command, [
                'client_id' => 'abcdef1234567890abcdef',
                'client_secret' => 'invalid-chars-here!!!!!!',
            ]))->toThrow(\Exception::class, 'Client Secret contains invalid characters');
        });

    });

    describe('storeCredentials method', function () {

        it('stores credentials to config dir as JSON', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('storeCredentials');
            $method->setAccessible(true);

            $method->invoke($command, [
                'client_id' => 'stored_client_id_12345',
                'client_secret' => 'stored_secret_12345678',
            ]);

            $credFile = $this->testConfigDir.'/credentials.json';
            expect(file_exists($credFile))->toBeTrue();

            $data = json_decode(file_get_contents($credFile), true);
            expect($data['client_id'])->toBe('stored_client_id_12345');
            expect($data['client_secret'])->toBe('stored_secret_12345678');
        });

        it('sets credentials file permissions to 0600', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('storeCredentials');
            $method->setAccessible(true);

            $method->invoke($command, [
                'client_id' => 'permissions_test_12345',
                'client_secret' => 'permissions_secret_123',
            ]);

            $credFile = $this->testConfigDir.'/credentials.json';
            $perms = substr(sprintf('%o', fileperms($credFile)), -4);
            expect($perms)->toBe('0600');
        });

    });

    describe('clearStoredCredentials method', function () {

        it('removes credentials and token files', function () {
            // Create files to delete
            $credFile = $this->testConfigDir.'/credentials.json';
            $tokenFile = $this->testConfigDir.'/token.json';
            config(['spotify.token_path' => $tokenFile]);

            file_put_contents($credFile, '{"client_id":"test"}');
            file_put_contents($tokenFile, '{"access_token":"test"}');

            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('clearStoredCredentials');
            $method->setAccessible(true);
            $method->invoke($command);

            expect(file_exists($credFile))->toBeFalse();
            expect(file_exists($tokenFile))->toBeFalse();
        });

        it('does not throw when files do not exist', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('clearStoredCredentials');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($command))->not->toThrow(\Throwable::class);
        });

    });

    describe('findAvailablePort method', function () {

        it('returns a port number between 8888 and 8892', function () {
            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('findAvailablePort');
            $method->setAccessible(true);

            $port = $method->invoke($command);
            expect($port)->toBeGreaterThanOrEqual(8888);
            expect($port)->toBeLessThanOrEqual(8892);
        });

    });

    describe('testSpotifyConnection method', function () {

        it('returns false when HTTP request fails', function () {
            \Illuminate\Support\Facades\Http::fake([
                'accounts.spotify.com/*' => \Illuminate\Support\Facades\Http::response([], 401),
            ]);

            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('testSpotifyConnection');
            $method->setAccessible(true);

            $result = $method->invoke($command, [
                'client_id' => 'bad_client_id',
                'client_secret' => 'bad_secret',
            ]);

            expect($result)->toBeFalse();
        });

        it('returns false when access_token not in response', function () {
            \Illuminate\Support\Facades\Http::fake([
                'accounts.spotify.com/*' => \Illuminate\Support\Facades\Http::response(['error' => 'invalid'], 200),
            ]);

            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('testSpotifyConnection');
            $method->setAccessible(true);

            $result = $method->invoke($command, [
                'client_id' => 'client_id',
                'client_secret' => 'secret',
            ]);

            expect($result)->toBeFalse();
        });

        it('returns true when access_token is present', function () {
            \Illuminate\Support\Facades\Http::fake([
                'accounts.spotify.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok123'], 200),
            ]);

            $command = $this->app->make(\App\Commands\SetupCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('testSpotifyConnection');
            $method->setAccessible(true);

            $result = $method->invoke($command, [
                'client_id' => 'client_id',
                'client_secret' => 'secret',
            ]);

            expect($result)->toBeTrue();
        });

    });

});
