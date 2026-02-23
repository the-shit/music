<?php

use App\Commands\DaemonCommand;
use Illuminate\Support\Facades\Config;

describe('DaemonCommand', function () {

    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/spotify-cli-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $_SERVER['HOME'] = $this->tempDir;

        Config::set('spotify.client_id', 'test_client_id');
        Config::set('spotify.client_secret', 'test_client_secret');
        Config::set('spotify.token_path', $this->tempDir.'/.config/spotify-cli/token.json');

        $this->app->forgetInstance(DaemonCommand::class);
        $this->app->bind(DaemonCommand::class, function () {
            return new DaemonCommand;
        });

        $this->configDir = $this->tempDir.'/.config/spotify-cli';
        $this->pidFile = $this->configDir.'/daemon.pid';
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

    describe('action routing', function () {

        it('handles invalid action', function () {
            $this->artisan('daemon', ['action' => 'invalid'])
                ->expectsOutputToContain('Invalid action: invalid')
                ->expectsOutputToContain('Available actions: start, stop, status')
                ->assertExitCode(1);
        });

        it('handles restart as invalid action', function () {
            $this->artisan('daemon', ['action' => 'restart'])
                ->expectsOutputToContain('Invalid action: restart')
                ->expectsOutputToContain('Available actions: start, stop, status')
                ->assertExitCode(1);
        });

        it('routes to start action', function () {
            $this->artisan('daemon', ['action' => 'start'])
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
            $cmd = 'sleep 60 > /dev/null 2>&1 & echo $!';
            $pid = (int) shell_exec($cmd);
            usleep(100000);

            expect(posix_kill($pid, 0))->toBeTrue();

            posix_kill($pid, SIGTERM);
            usleep(100000);

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
            $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
            $this->artisan('daemon');
        });

        it('accepts start as valid action argument', function () {
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
            $pidWithNewline = "12345\n";
            expect((int) $pidWithNewline)->toBe(12345);
        });

    });

    describe('graceful shutdown', function () {

        it('SIGTERM stops processes gracefully', function () {
            $cmd = 'sleep 60 > /dev/null 2>&1 & echo $!';
            $pid = (int) shell_exec($cmd);
            usleep(100000);

            expect(posix_kill($pid, 0))->toBeTrue();

            posix_kill($pid, SIGTERM);
            usleep(100000);

            expect(@posix_kill($pid, 0))->toBeFalse();
        });

    });

    describe('error handling', function () {

        it('handles missing config directory gracefully on stop', function () {
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

        it('shows device guidance when not running', function () {
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
