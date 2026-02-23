<?php

describe('LoginCommand', function () {

    beforeEach(function () {
        // Point config_dir to an empty temp dir so no real credentials file is read
        $this->testConfigDir = sys_get_temp_dir().'/login-test-'.uniqid();
        mkdir($this->testConfigDir, 0755, true);
        config(['spotify.config_dir' => $this->testConfigDir]);
        config(['spotify.token_path' => $this->testConfigDir.'/token.json']);
    });

    afterEach(function () {
        if (isset($this->testConfigDir) && is_dir($this->testConfigDir)) {
            array_map('unlink', glob($this->testConfigDir.'/*') ?: []);
            rmdir($this->testConfigDir);
        }
    });

    describe('missing credentials', function () {

        it('fails when credentials file is missing entirely', function () {
            // No credentials.json in testConfigDir → both null → failure
            $this->artisan('login')
                ->expectsOutputToContain('Missing Spotify credentials')
                ->assertExitCode(1);
        });

        it('fails when client_id is missing from credentials file', function () {
            file_put_contents($this->testConfigDir.'/credentials.json', json_encode([
                'client_id' => null,
                'client_secret' => 'test-secret-123',
            ]));

            $this->artisan('login')
                ->expectsOutputToContain('Missing Spotify credentials')
                ->assertExitCode(1);
        });

        it('fails when client_secret is missing from credentials file', function () {
            file_put_contents($this->testConfigDir.'/credentials.json', json_encode([
                'client_id' => 'test-id-123',
                'client_secret' => null,
            ]));

            $this->artisan('login')
                ->expectsOutputToContain('Missing Spotify credentials')
                ->assertExitCode(1);
        });

        it('fails when client_id is empty string in credentials file', function () {
            file_put_contents($this->testConfigDir.'/credentials.json', json_encode([
                'client_id' => '',
                'client_secret' => 'test-secret-123',
            ]));

            $this->artisan('login')
                ->expectsOutputToContain('Missing Spotify credentials')
                ->assertExitCode(1);
        });

    });

    describe('command metadata', function () {

        it('has correct command name', function () {
            $command = $this->app->make(\App\Commands\LoginCommand::class);
            expect($command->getName())->toBe('login');
        });

        it('has a description', function () {
            $command = $this->app->make(\App\Commands\LoginCommand::class);
            expect($command->getDescription())->not->toBeEmpty();
        });

    });

    describe('findAvailablePort', function () {

        it('returns a port in the expected range', function () {
            $command = $this->app->make(\App\Commands\LoginCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('findAvailablePort');
            $method->setAccessible(true);

            $port = $method->invoke($command);
            expect($port)->toBeGreaterThanOrEqual(8888);
            expect($port)->toBeLessThanOrEqual(8892);
        });

    });

    describe('createCallbackServer', function () {

        it('creates a PHP callback server script in temp dir', function () {
            $command = $this->app->make(\App\Commands\LoginCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('createCallbackServer');
            $method->setAccessible(true);

            $scriptPath = $method->invoke($command);

            expect(file_exists($scriptPath))->toBeTrue();
            expect($scriptPath)->toContain(sys_get_temp_dir());

            $content = file_get_contents($scriptPath);
            expect($content)->toContain('/callback');
            expect($content)->toContain('code');

            @unlink($scriptPath);
        });

    });

    describe('waitForAuthCode', function () {

        it('returns null immediately when timeout is 0 and no code file present', function () {
            // Clean slate
            $codeFile = sys_get_temp_dir().'/spotify_code.txt';
            @unlink($codeFile);

            $command = $this->app->make(\App\Commands\LoginCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('waitForAuthCode');
            $method->setAccessible(true);

            // Pass timeout=0 so the loop exits immediately without blocking
            $result = $method->invoke($command, 0);
            expect($result)->toBeNull();
        });

        it('reads and returns code when file appears during polling', function () {
            // We test the code-found branch by subclassing waitForAuthCode behaviour:
            // since the method clears then polls, we write the file from a background
            // process with enough delay to survive the unlink and appear on first poll.
            $codeFile = sys_get_temp_dir().'/spotify_code.txt';
            @unlink($codeFile);

            // Start background writer — waits 0.2s (2 poll cycles) then writes
            $pid = (int) shell_exec('(sleep 0.2 && printf "auth_code_xyz" > '.escapeshellarg($codeFile).') > /dev/null 2>&1 & echo $!');

            $command = $this->app->make(\App\Commands\LoginCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('waitForAuthCode');
            $method->setAccessible(true);

            $result = $method->invoke($command, 3); // 3s timeout

            @unlink($codeFile);
            @posix_kill($pid, SIGTERM);

            expect($result)->toBe('auth_code_xyz');
        });

    });

    describe('exchangeCodeForToken', function () {

        it('returns null when HTTP request fails', function () {
            \Illuminate\Support\Facades\Http::fake([
                'accounts.spotify.com/*' => \Illuminate\Support\Facades\Http::response([], 400),
            ]);

            $command = $this->app->make(\App\Commands\LoginCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('exchangeCodeForToken');
            $method->setAccessible(true);

            $result = $method->invoke($command, 'client_id', 'client_secret', 'code123', 'http://127.0.0.1:8888/callback');
            expect($result)->toBeNull();
        });

        it('returns token array on successful exchange', function () {
            \Illuminate\Support\Facades\Http::fake([
                'accounts.spotify.com/*' => \Illuminate\Support\Facades\Http::response([
                    'access_token' => 'access_tok_123',
                    'refresh_token' => 'refresh_tok_456',
                    'expires_in' => 3600,
                ], 200),
            ]);

            $command = $this->app->make(\App\Commands\LoginCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('exchangeCodeForToken');
            $method->setAccessible(true);

            $result = $method->invoke($command, 'client_id', 'client_secret', 'code123', 'http://127.0.0.1:8888/callback');
            expect($result)->not->toBeNull();
            expect($result['access_token'])->toBe('access_tok_123');
            expect($result['refresh_token'])->toBe('refresh_tok_456');
            expect($result)->toHaveKey('expires_at');
        });

    });

});
