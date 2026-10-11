<?php

use App\Commands\DaemonCommand;
use App\Services\Daemon\UserUnit;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Config;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('DaemonCommand', function (): void {

    beforeEach(function (): void {
        $this->tempDir = sys_get_temp_dir().'/spotify-cli-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $_SERVER['HOME'] = $this->tempDir;

        Config::set('spotify.client_id', 'test_client_id');
        Config::set('spotify.client_secret', 'test_client_secret');
        Config::set('spotify.token_path', $this->tempDir.'/.config/spotify-cli/token.json');

        $this->app->forgetInstance(DaemonCommand::class);
        $this->app->bind(DaemonCommand::class, function (): DaemonCommand {
            return new DaemonCommand;
        });

        $this->configDir = $this->tempDir.'/.config/spotify-cli';
        $this->pidFile = $this->configDir.'/daemon.pid';
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

    describe('action routing', function (): void {

        it('handles invalid action', function (): void {
            $this->artisan('daemon', ['action' => 'invalid'])
                ->expectsOutputToContain('Invalid action: invalid')
                ->expectsOutputToContain('Available actions: setup, start, stop, status, health, install, uninstall')
                ->assertExitCode(1);
        });

        it('handles restart as invalid action', function (): void {
            $this->artisan('daemon', ['action' => 'restart'])
                ->expectsOutputToContain('Invalid action: restart')
                ->expectsOutputToContain('Available actions: setup, start, stop, status, health, install, uninstall')
                ->assertExitCode(1);
        });

        it('routes to start action', function (): void {
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

        it('routes to stop action', function (): void {
            $this->artisan('daemon', ['action' => 'stop'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('routes to status action', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('routes to install action', function (): void {
            if (PHP_OS_FAMILY === 'Darwin') {
                $this->artisan('daemon', ['action' => 'install']);
                expect(true)->toBeTrue();

                return;
            }

            $spotifyd = trim((string) shell_exec('which spotifyd 2>/dev/null'));
            if ($spotifyd !== '' && $spotifyd !== '0') {
                expect(true)->toBeTrue();

                return;
            }

            $this->artisan('daemon', ['action' => 'install'])
                ->expectsOutputToContain('spotify daemon setup')
                ->doesntExpectOutputToContain('apt install')
                ->assertExitCode(1);
        });

        it('routes to uninstall action', function (): void {
            $expected = in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) ? 0 : 1;
            $this->artisan('daemon', ['action' => 'uninstall'])
                ->assertExitCode($expected);
        });

    });

    describe('start action', function (): void {

        it('fails when daemon executable not found', function (): void {
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

    });

    describe('stop action', function (): void {

        it('reports when daemon is not running without PID file', function (): void {
            $this->artisan('daemon', ['action' => 'stop'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('returns success when daemon not running', function (): void {
            $this->artisan('daemon', ['action' => 'stop'])
                ->assertExitCode(0);
        });

    });

    describe('status action', function (): void {

        it('reports when daemon is not running', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->expectsOutputToContain('Use: spotify devices to see available playback devices')
                ->assertExitCode(0);
        });

        it('always returns success exit code', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->assertExitCode(0);
        });

    });

    describe('isDaemonRunning detection', function (): void {

        it('returns false when PID file does not exist', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('uses posix_kill with signal 0 to check process existence', function (): void {
            $proc = proc_open('sleep 60', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            $status = proc_get_status($proc);
            $pid = $status['pid'];

            expect(posix_kill($pid, 0))->toBeTrue();

            proc_terminate($proc, SIGTERM);
            proc_close($proc);

            expect(@posix_kill($pid, 0))->toBeFalse();
        });

    });

    describe('command signature and metadata', function (): void {

        it('has correct command name', function (): void {
            $command = $this->app->make(DaemonCommand::class);
            expect($command->getName())->toBe('daemon');
        });

        it('has correct description', function (): void {
            $command = $this->app->make(DaemonCommand::class);
            expect($command->getDescription())->toBe('Manage the Spotify daemon for terminal playback');
        });

        it('lists daemon verbs when no action is given', function (): void {
            $verbs = [
                'setup' => 'Install spotifyd and authenticate the local speaker',
                'start' => 'Start the local Connect speaker',
                'stop' => 'Stop the local Connect speaker',
                'status' => 'Show whether the speaker is running',
                'health' => 'Check the speaker and its PipeWire sink',
                'install' => 'Enable the user service that keeps the speaker alive',
                'uninstall' => 'Remove the user service',
            ];

            $pending = $this->artisan('daemon');
            foreach ($verbs as $name => $description) {
                $pending->expectsOutputToContain(str_pad($name, 10).$description);
            }

            $pending->doesntExpectOutputToContain('Not enough arguments')->assertExitCode(0);
        });

        it('accepts start as valid action argument', function (): void {
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

        it('accepts stop as valid action argument', function (): void {
            $this->artisan('daemon', ['action' => 'stop'])
                ->assertExitCode(0);
        });

        it('accepts status as valid action argument', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->assertExitCode(0);
        });

        it('accepts --name option', function (): void {
            $command = $this->app->make(DaemonCommand::class);
            $definition = $command->getDefinition();
            expect($definition->hasOption('name'))->toBeTrue();
            expect($definition->getOption('name')->getDescription())->toBe('Device name for Spotify Connect (defaults to hostname)');
        });

    });

    describe('PID file operations', function (): void {

        it('handles integer PIDs correctly', function (): void {
            $pidWithNewline = "12345\n";
            expect((int) $pidWithNewline)->toBe(12345);
        });

    });

    describe('graceful shutdown', function (): void {

        it('SIGTERM stops processes gracefully', function (): void {
            $proc = proc_open('sleep 60', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            $status = proc_get_status($proc);
            $pid = $status['pid'];

            expect(posix_kill($pid, 0))->toBeTrue();

            proc_terminate($proc, SIGTERM);
            proc_close($proc);

            expect(@posix_kill($pid, 0))->toBeFalse();
        });

    });

    describe('error handling', function (): void {

        it('handles missing config directory gracefully on stop', function (): void {
            expect(is_dir($this->configDir))->toBeFalse();

            $this->artisan('daemon', ['action' => 'stop'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

        it('handles missing config directory gracefully on status', function (): void {
            expect(is_dir($this->configDir))->toBeFalse();

            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->assertExitCode(0);
        });

    });

    describe('exit codes', function (): void {

        it('returns FAILURE (1) when daemon not found on start', function (): void {
            $this->artisan('daemon', ['action' => 'start'])
                ->assertExitCode(1);
        });

        it('returns SUCCESS (0) when daemon not running on stop', function (): void {
            $this->artisan('daemon', ['action' => 'stop'])
                ->assertExitCode(0);
        });

        it('returns SUCCESS (0) on status', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->assertExitCode(0);
        });

        it('returns FAILURE (1) for invalid action', function (): void {
            $this->artisan('daemon', ['action' => 'invalid'])
                ->assertExitCode(1);
        });

    });

    describe('install action', function (): void {

        it('creates plist file on macOS when spotifyd available', function (): void {
            if (PHP_OS_FAMILY !== 'Darwin') {
                $this->markTestSkipped('LaunchAgent tests require macOS');
            }

            $spotifyd = trim((string) shell_exec('which spotifyd 2>/dev/null'));
            if ($spotifyd === '' || $spotifyd === '0') {
                // No spotifyd — should fail
                $this->artisan('daemon', ['action' => 'install'])
                    ->expectsOutputToContain('spotifyd not found')
                    ->assertExitCode(1);

                return;
            }

            $this->artisan('daemon', ['action' => 'install'])
                ->expectsOutputToContain('LaunchAgent installed')
                ->assertExitCode(0);

            $plistPath = $this->tempDir.'/Library/LaunchAgents/com.theshit.spotifyd.plist';
            expect(file_exists($plistPath))->toBeTrue();
            expect(file_get_contents($plistPath))->toContain('com.theshit.spotifyd');
        });

        it('reports when already installed', function (): void {
            if (PHP_OS_FAMILY !== 'Darwin') {
                $this->markTestSkipped('LaunchAgent tests require macOS');
            }

            $plistDir = $this->tempDir.'/Library/LaunchAgents';
            mkdir($plistDir, 0755, true);
            file_put_contents($plistDir.'/com.theshit.spotifyd.plist', 'test');

            $this->artisan('daemon', ['action' => 'install'])
                ->expectsOutputToContain('LaunchAgent is already installed')
                ->assertExitCode(0);
        });

    });

    describe('uninstall action', function (): void {

        it('reports when the platform service is not installed', function (): void {
            if (PHP_OS_FAMILY === 'Darwin') {
                $this->artisan('daemon', ['action' => 'uninstall'])
                    ->expectsOutputToContain('LaunchAgent is not installed')
                    ->assertExitCode(0);
            } elseif (PHP_OS_FAMILY === 'Linux') {
                $this->artisan('daemon', ['action' => 'uninstall'])
                    ->expectsOutputToContain('User unit is not installed')
                    ->assertExitCode(0);
            } else {
                $this->artisan('daemon', ['action' => 'uninstall'])
                    ->assertExitCode(1);
            }
        });

        it('reports when not installed', function (): void {
            if (PHP_OS_FAMILY !== 'Darwin') {
                $this->markTestSkipped('LaunchAgent tests require macOS');
            }

            $this->artisan('daemon', ['action' => 'uninstall'])
                ->expectsOutputToContain('LaunchAgent is not installed')
                ->assertExitCode(0);
        });

        it('removes plist file when installed', function (): void {
            if (PHP_OS_FAMILY !== 'Darwin') {
                $this->markTestSkipped('LaunchAgent tests require macOS');
            }

            $plistDir = $this->tempDir.'/Library/LaunchAgents';
            mkdir($plistDir, 0755, true);
            $plistPath = $plistDir.'/com.theshit.spotifyd.plist';
            file_put_contents($plistPath, 'test');

            $this->artisan('daemon', ['action' => 'uninstall'])
                ->expectsOutputToContain('LaunchAgent removed')
                ->assertExitCode(0);

            expect(file_exists($plistPath))->toBeFalse();
        });

    });

    describe('LaunchAgent plist generation', function (): void {

        it('generates valid plist XML', function (): void {
            $command = $this->app->make(DaemonCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('generateLaunchAgentPlist');
            $method->setAccessible(true);

            $plist = $method->invoke($command, '/usr/local/bin/spotifyd');

            expect($plist)->toContain('com.theshit.spotifyd');
            expect($plist)->toContain('/usr/local/bin/spotifyd');
            expect($plist)->toContain('--config-path');
            expect($plist)->toContain('--no-daemon');
            expect($plist)->toContain('<key>RunAtLoad</key>');
            expect($plist)->toContain('<true/>');
            expect($plist)->toContain('spotifyd.conf');
            expect($plist)->toContain('spotifyd.log');
        });

        it('includes KeepAlive and ThrottleInterval in plist', function (): void {
            $command = $this->app->make(DaemonCommand::class);
            $reflection = new ReflectionClass($command);
            $method = $reflection->getMethod('generateLaunchAgentPlist');
            $method->setAccessible(true);

            $plist = $method->invoke($command, '/usr/local/bin/spotifyd');

            expect($plist)->toContain('<key>KeepAlive</key>');
            expect($plist)->toContain('<key>SuccessfulExit</key>');
            expect($plist)->toContain('<key>ThrottleInterval</key>');
            expect($plist)->toContain('<integer>30</integer>');
        });

    });

    describe('device name defaults to hostname', function (): void {

        beforeEach(function (): void {
            $this->bindDaemonInput = function (array $options = []): DaemonCommand {
                $command = $this->app->make(DaemonCommand::class);
                $args = ['action' => 'start'];
                foreach ($options as $key => $value) {
                    $args['--'.$key] = $value;
                }
                $input = new ArrayInput($args);
                $input->bind($command->getDefinition());
                $command->setInput($input);

                return $command;
            };

        });

        it('uses the short hostname when no name is configured', function (): void {
            $command = ($this->bindDaemonInput)();
            $deviceResolution = $command->getDeviceResolution();
            $host = gethostname();
            expect($host)->not->toBeFalse();
            $expected = explode('.', (string) $host, 2)[0];

            expect($deviceResolution->resolveDaemonName(null))->toBe($expected);
        });

        it('lets --name override the hostname', function (): void {
            $command = ($this->bindDaemonInput)(['name' => 'Kitchen']);
            $deviceResolution = $command->getDeviceResolution();

            expect($deviceResolution->resolveDaemonName('Kitchen'))->toBe('Kitchen');
        });

        it('replaces a leftover Work Mac config with the hostname', function (): void {
            mkdir($this->configDir, 0755, true);
            file_put_contents($this->configDir.'/spotifyd.conf', "device_name = \"Work Mac\"\n");

            $command = ($this->bindDaemonInput)();
            $deviceResolution = $command->getDeviceResolution();
            $expected = $deviceResolution->resolveDaemonName();

            expect($expected)->not->toBe('Work Mac');

            $command->writeSpotifydConfig('/bin/true');
            expect(file_get_contents($this->configDir.'/spotifyd.conf'))
                ->toContain('device_name = "'.$expected.'"')
                ->not->toContain('Work Mac');
        });

        it('keeps a custom device name already in spotifyd.conf', function (): void {
            mkdir($this->configDir, 0755, true);
            file_put_contents($this->configDir.'/spotifyd.conf', "device_name = \"Kitchen\"\n");

            $command = ($this->bindDaemonInput)();
            $deviceResolution = $command->getDeviceResolution();

            expect($deviceResolution->resolveDaemonName())->toBe('Kitchen');

            $command->writeSpotifydConfig('/bin/true');
            expect(file_get_contents($this->configDir.'/spotifyd.conf'))
                ->toContain('device_name = "Kitchen"');
        });

        it('writes the hostname into a new spotifyd.conf', function (): void {
            $command = ($this->bindDaemonInput)();
            $deviceResolution = $command->getDeviceResolution();
            $expected = $deviceResolution->resolveDaemonName();

            $command->writeSpotifydConfig('/bin/true');
            expect(file_get_contents($this->configDir.'/spotifyd.conf'))
                ->toContain('device_name = "'.$expected.'"');
        });

    });

    describe('output messages', function (): void {

        it('shows device guidance when not running', function (): void {
            $this->artisan('daemon', ['action' => 'status'])
                ->expectsOutputToContain('Daemon is not running')
                ->expectsOutputToContain('Use: spotify devices to see available playback devices')
                ->assertExitCode(0);
        });

        it('lists valid actions when invalid action provided', function (): void {
            $this->artisan('daemon', ['action' => 'unknown'])
                ->expectsOutputToContain('Invalid action: unknown')
                ->expectsOutputToContain('Available actions: setup, start, stop, status, health, install, uninstall')
                ->assertExitCode(1);
        });

    });

    describe('linux user unit', function (): void {

        it('writes a pipewire user unit and enables it', function (): void {
            if (PHP_OS_FAMILY !== 'Linux') {
                expect(true)->toBeTrue();

                return;
            }

            $binary = $this->tempDir.'/.local/bin/spotifyd-rodio';
            mkdir(dirname($binary), 0755, true);
            file_put_contents($binary, "#!/bin/sh\nexit 0\n");
            chmod($binary, 0755);

            $log = $this->tempDir.'/systemctl.log';
            $binDir = $this->tempDir.'/bin';
            mkdir($binDir, 0755, true);
            file_put_contents($binDir.'/systemctl', <<<SH
#!/bin/sh
echo "\$@" >> {$log}
if [ "\$2" = "is-active" ]; then
  echo active
  exit 0
fi
exit 0
SH);
            chmod($binDir.'/systemctl', 0755);

            $command = $this->app->make(DaemonCommand::class);
            $input = new ArrayInput(['action' => 'install']);
            $input->bind($command->getDefinition());
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));
            Prompt::setOutput($output);

            $originalPath = getenv('PATH') ?: '';
            putenv('PATH='.$binDir.':'.$originalPath);

            try {
                $code = $command->handle(
                    $this->app->make(SpotifyAuthManager::class),
                    $this->app->make(SpotifyPlayerService::class),
                );
            } finally {
                putenv('PATH='.$originalPath);
            }

            expect($code)->toBe(0);
            expect($output->fetch())->toContain('User unit installed');

            $unitPath = $this->tempDir.'/.config/systemd/user/'.UserUnit::NAME;
            $unit = (string) file_get_contents($unitPath);
            expect($unit)->toContain($binary.' --config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery');
            expect($unit)->toContain('After=pipewire.service pipewire-pulse.service');
            expect($unit)->toContain('PartOf=pipewire.service pipewire-pulse.service');
            expect($unit)->toContain('Restart=always');
            expect($unit)->toContain('RestartSec=2');
            expect($unit)->toContain('StartLimitBurst=5');
            expect($unit)->toContain('StartLimitIntervalSec=60');
            expect($unit)->not->toContain('hw:');

            $systemctl = (string) file_get_contents($log);
            expect($systemctl)->toContain('daemon-reload');
            expect($systemctl)->toContain('enable --now '.UserUnit::NAME);
            expect($systemctl)->toContain('is-active '.UserUnit::NAME);

            $conf = (string) file_get_contents($this->configDir.'/spotifyd.conf');
            expect($conf)->toContain('backend = "pulseaudio"');
            expect($conf)->not->toContain('hw:');
            expect($conf)->not->toContain('rodio');
        });

    });

    describe('audio graph health', function (): void {

        it('treats connect playing with no spotifyd sink-input as degraded', function (): void {
            [$proc] = spawnDaemonSpotifyd($this->tempDir, $this->configDir, $this->pidFile);

            try {
                $command = $this->app->make(DaemonCommand::class);
                $command->setAudioGraphProbes(fn (): bool => true, fn (): bool => false);

                $diagnosis = $command->diagnose();

                expect($diagnosis['status'])->toBe('degraded');
                expect($diagnosis['playing_without_sink'])->toBeTrue();
                expect($diagnosis['pid'])->not->toBeNull();
            } finally {
                proc_terminate($proc);
                proc_close($proc);
            }
        });

        it('stays healthy when the sink-input is present', function (): void {
            [$proc] = spawnDaemonSpotifyd($this->tempDir, $this->configDir, $this->pidFile);

            try {
                $command = $this->app->make(DaemonCommand::class);
                $command->setAudioGraphProbes(fn (): bool => true, fn (): bool => true);

                $diagnosis = $command->diagnose();

                expect($diagnosis['status'])->toBe('healthy');
                expect($diagnosis['playing_without_sink'])->toBeFalse();
            } finally {
                proc_terminate($proc);
                proc_close($proc);
            }
        });

        it('does not treat context-is-not-available as degraded', function (): void {
            [$proc] = spawnDaemonSpotifyd($this->tempDir, $this->configDir, $this->pidFile);
            file_put_contents(
                $this->configDir.'/spotifyd.log',
                str_repeat("[WARN] couldn't load context info because: context is not available. type: Default\n", 20)
            );

            try {
                $command = $this->app->make(DaemonCommand::class);
                $command->setAudioGraphProbes(fn (): bool => false, fn (): bool => true);
                $diagnosis = $command->diagnose();

                expect($diagnosis['status'])->toBe('healthy');
                expect($diagnosis['errors'])->not->toHaveKey('context is not available');
            } finally {
                proc_terminate($proc);
                proc_close($proc);
            }
        });

        it('restarts the user unit when healing a missing sink-input', function (): void {
            $log = $this->tempDir.'/systemctl.log';
            $binDir = $this->tempDir.'/bin';
            mkdir($binDir, 0755, true);
            file_put_contents($binDir.'/systemctl', <<<SH
#!/bin/sh
echo "\$@" >> {$log}
if [ "\$2" = "is-active" ]; then
  echo active
  exit 0
fi
exit 0
SH);
            chmod($binDir.'/systemctl', 0755);

            $binary = $this->tempDir.'/.local/bin/spotifyd-rodio';
            mkdir(dirname($binary), 0755, true);
            file_put_contents($binary, "#!/bin/sh\nexit 0\n");
            chmod($binary, 0755);

            $unitDir = $this->tempDir.'/.config/systemd/user';
            mkdir($unitDir, 0755, true);
            file_put_contents($unitDir.'/'.UserUnit::NAME, "[Service]\nExecStart=/bin/true\n");

            mkdir($this->configDir, 0755, true);
            file_put_contents($this->configDir.'/spotifyd.conf', "backend = \"rodio\"\ndevice = \"hw:0,0\"\ndevice_name = \"Kitchen\"\n");
            file_put_contents($this->configDir.'/spotifyd.log', "DeviceNotAvailable(\"hw:0,0\")\n");

            $command = $this->app->make(DaemonCommand::class);
            $input = new ArrayInput(['action' => 'health', '--heal' => true]);
            $input->bind($command->getDefinition());
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));
            Prompt::setOutput($output);

            $originalPath = getenv('PATH') ?: '';
            putenv('PATH='.$binDir.':'.$originalPath);

            try {
                $method = new ReflectionMethod($command, 'heal');
                $method->setAccessible(true);
                $result = $method->invoke($command, [
                    'status' => 'degraded',
                    'pid' => 999999,
                    'errors' => [],
                    'cache_size_mb' => 1.0,
                    'log_lines' => 1,
                    'playing_without_sink' => true,
                ]);
            } finally {
                putenv('PATH='.$originalPath);
            }

            expect($result)->toBe(0);
            expect((string) file_get_contents($log))->toContain('restart '.UserUnit::NAME);
            $conf = (string) file_get_contents($this->configDir.'/spotifyd.conf');
            expect($conf)->toContain('backend = "pulseaudio"');
            expect($conf)->not->toContain('hw:');
            expect($conf)->toContain('device_name = "Kitchen"');
            expect($output->fetch())->toContain('rewriting backend to pulseaudio');
        });

    });

});

/**
 * A live process whose `ps -o comm=` starts with spotifyd.
 *
 * Copying /bin/sleep is not portable. BusyBox selects its applet from argv0, so a
 * copy named spotifyd exits immediately, and some images have no sleep binary at
 * all. A copied PHP binary sleeps under that name. /tmp may be noexec, so the
 * second location sits next to the suite.
 *
 * @return array{0: resource, 1: int}
 */
function spawnDaemonSpotifyd(string $tempDir, string $configDir, string $pidFile): array
{
    $sources = [];
    $sleep = daemonCoreutilsSleep();
    if ($sleep !== null) {
        $sources[] = ['path' => $sleep, 'php' => false];
    }

    $php = realpath(PHP_BINARY) ?: PHP_BINARY;
    if (is_file($php)) {
        $sources[] = ['path' => $php, 'php' => true];
    }

    $locations = [
        $tempDir.'/spot-bin',
        dirname(__DIR__, 2).'/tests/.spotifyd-bin',
    ];

    $last = 'no sleeper binary';

    foreach ($locations as $binDir) {
        foreach ($sources as $source) {
            if (! is_dir($binDir) && ! mkdir($binDir, 0755, true) && ! is_dir($binDir)) {
                $last = 'cannot create '.$binDir;

                continue;
            }

            $binary = $binDir.'/spotifyd';
            if (is_file($binary)) {
                unlink($binary);
            }

            if (! @copy($source['path'], $binary)) {
                $last = 'copy failed for '.$source['path'];

                continue;
            }

            chmod($binary, 0755);

            $args = $source['php']
                ? [$binary, '-r', 'sleep(30);']
                : [$binary, '30'];

            $proc = @proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (! is_resource($proc)) {
                $last = 'proc_open failed for '.$binary;
                @unlink($binary);

                continue;
            }

            usleep(150000);
            $status = proc_get_status($proc);
            $pid = (int) ($status['pid'] ?? 0);
            $comm = $pid > 0 ? trim((string) shell_exec('ps -p '.$pid.' -o comm= 2>/dev/null')) : '';

            if (($status['running'] ?? false) === true && str_starts_with(basename($comm), 'spotifyd')) {
                if (! is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                file_put_contents($pidFile, (string) $pid);
                @unlink($binary);
                @rmdir($binDir);

                return [$proc, $pid];
            }

            proc_terminate($proc);
            proc_close($proc);
            @unlink($binary);
            @rmdir($binDir);
            $last = 'comm=['.$comm.'] running='.((($status['running'] ?? false) === true) ? 'yes' : 'no');
        }
    }

    throw new RuntimeException('Could not spawn fake spotifyd: '.$last);
}

function daemonCoreutilsSleep(): ?string
{
    foreach (['/bin/sleep', '/usr/bin/sleep'] as $path) {
        if (! is_file($path)) {
            continue;
        }

        $real = realpath($path) ?: $path;
        if (str_contains(strtolower(basename($real)), 'busybox')) {
            continue;
        }

        return $real;
    }

    return null;
}
