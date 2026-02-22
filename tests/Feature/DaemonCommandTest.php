<?php

use App\Commands\DaemonCommand;
use App\Services\SpotifyService;
use Illuminate\Support\Facades\Config;

describe('DaemonCommand', function () {

    beforeEach(function () {
        // Set up a temporary config directory for testing
        $this->tempDir = sys_get_temp_dir().'/spotify-cli-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Override HOME for the test - must be done before DaemonCommand is constructed
        $_SERVER['HOME'] = $this->tempDir;

        // Set config values to prevent null assignment errors in SpotifyService
        Config::set('spotify.client_id', 'test_client_id');
        Config::set('spotify.client_secret', 'test_client_secret');
        Config::set('spotify.token_path', $this->tempDir.'/.config/spotify-cli/token.json');

        // Force a fresh instance of DaemonCommand to pick up new HOME
        $this->app->forgetInstance(DaemonCommand::class);
        $this->app->bind(DaemonCommand::class, function () {
            return new DaemonCommand;
        });

        $this->configDir = $this->tempDir.'/.config/spotify-cli';
        $this->pidFile = $this->configDir.'/daemon.pid';
    });

    afterEach(function () {
        // Clean up temporary directory
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            // Remove all files and directories recursively
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($this->tempDir);
        }
    });

    describe('action routing', function () {

        it('handles invalid action', function () {
            $this->artisan('daemon', ['action' => 'invalid'])
                ->expectsOutputToContain('Invalid action: invalid')
                ->expectsOutputToContain('Available actions: start, stop, status')
                ->assertExitCode(1);
        });

        it('handles restart as invalid action', function () {
            // Note: restart is not implemented in the command
            $this->artisan('daemon', ['action' => 'restart'])
                ->expectsOutputToContain('Invalid action: restart')
                ->expectsOutputToContain('Available actions: start, stop, status')
                ->assertExitCode(1);
        });

        it('routes to start action', function () {
            // Start action will fail because spotifyd/librespot is not installed
            // This test verifies the start action is routed correctly
            $this->artisan('daemon', ['action' => 'start'])
                // Either spotifyd not found or not configured
                ->assertExitCode(1);
        });

        it('routes to stop action', function () {
            $this->artisan('daemon', ['action' => 'stop'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('routes to status action', function () {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

    });

    describe('start action', function () {

        it('fails when daemon executable not found', function () {
            // In test environments, spotifyd/librespot won't be found
            // Start will fail with exit code 1
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

    });

    describe('stop action', function () {

        it('reports when daemon is not running without PID file', function () {
            $this->artisan('daemon', ['action' => 'stop'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('returns success when daemon not running', function () {
            // Stop should succeed even when no daemon is running
            $this->artisan('daemon', ['action' => 'stop'])
                ->assertExitCode(0);
        });

    });

    describe('status action', function () {

        it('reports when daemon is not running', function () {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->expectsOutputToContain('Use: spotify devices to see available playback devices')
                ->assertExitCode(0);
        });

        it('always returns success exit code', function () {
            // Status should always return 0, whether running or not
            $this->artisan('daemon', ['action' => 'status'])
                ->assertExitCode(0);
        });

    });

    describe('isDaemonRunning detection', function () {

        it('returns false when PID file does not exist', function () {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('uses posix_kill with signal 0 to check process existence', function () {
            // Test the underlying posix_kill behavior
            $cmd = 'sleep 60 > /dev/null 2>&1 & echo $!';
            $pid = (int) shell_exec($cmd);
            usleep(100000);

            // Verify posix_kill signal 0 returns true for running process
            expect(posix_kill($pid, 0))->toBeTrue();

            // Stop the process
            posix_kill($pid, SIGTERM);
            usleep(100000);

            // Verify posix_kill signal 0 returns false for dead process
            expect(@posix_kill($pid, 0))->toBeFalse();
        });

    });

    describe('command signature and metadata', function () {

        it('has correct command name', function () {
            $command = $this->app->make(DaemonCommand::class);
            expect($command->getName())->toBe('daemon');
        });

        it('has correct description', function () {
            $command = $this->app->make(DaemonCommand::class);
            expect($command->getDescription())->toBe('Manage the Spotify daemon for terminal playback (experimental)');
        });

        it('requires action argument', function () {
            // Attempting to run without action should fail
            $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
            $this->artisan('daemon');
        });

        it('accepts start as valid action argument', function () {
            // start is valid but will fail (no daemon found in test env)
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

        it('accepts stop as valid action argument', function () {
            $this->artisan('daemon', ['action' => 'stop'])
                ->assertExitCode(0);
        });

        it('accepts status as valid action argument', function () {
            $this->artisan('daemon', ['action' => 'status'])
                ->assertExitCode(0);
        });

    });

    describe('PID file operations', function () {

        it('handles integer PIDs correctly', function () {
            // Test that (int) cast handles PID strings
            $pidWithNewline = "12345\n";
            expect((int) $pidWithNewline)->toBe(12345);
        });

    });

    describe('graceful shutdown', function () {

        it('SIGTERM stops processes gracefully', function () {
            // Test that SIGTERM works for stopping processes
            $cmd = 'sleep 60 > /dev/null 2>&1 & echo $!';
            $pid = (int) shell_exec($cmd);
            usleep(100000);

            expect(posix_kill($pid, 0))->toBeTrue();

            // Send SIGTERM
            posix_kill($pid, SIGTERM);
            usleep(100000);

            expect(@posix_kill($pid, 0))->toBeFalse();
        });

    });

    describe('error handling', function () {

        it('handles missing config directory gracefully on stop', function () {
            // Config directory doesn't exist, stop should still work
            expect(is_dir($this->configDir))->toBeFalse();

            $this->artisan('daemon', ['action' => 'stop'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('handles missing config directory gracefully on status', function () {
            expect(is_dir($this->configDir))->toBeFalse();

            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

    });

    describe('exit codes', function () {

        it('returns FAILURE (1) when daemon not found on start', function () {
            // In test environment, spotifyd/librespot won't be found
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

        it('returns SUCCESS (0) when daemon not running on stop', function () {
            $this->artisan('daemon', ['action' => 'stop'])
                ->assertExitCode(0);
        });

        it('returns SUCCESS (0) on status', function () {
            $this->artisan('daemon', ['action' => 'status'])
                ->assertExitCode(0);
        });

        it('returns FAILURE (1) for invalid action', function () {
            $this->artisan('daemon', ['action' => 'invalid'])
                ->assertExitCode(1);
        });

    });

    describe('output messages', function () {

        it('shows start guidance when not running', function () {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->expectsOutputToContain('Use: spotify devices to see available playback devices')
                ->assertExitCode(0);
        });

        it('lists valid actions when invalid action provided', function () {
            $this->artisan('daemon', ['action' => 'unknown'])
                ->expectsOutputToContain('Invalid action: unknown')
                ->expectsOutputToContain('Available actions: start, stop, status')
                ->assertExitCode(1);
        });

    });

});
