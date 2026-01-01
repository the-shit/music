<?php

namespace App\Commands;

use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class DaemonCommand extends Command
{
    protected $signature = 'daemon {action : start, stop, or status}';

    protected $description = 'Manage the spotifyd/librespot daemon for terminal playback';

    private string $pidFile;

    private string $configDir;

    private string $credentialsFile;

    public function __construct()
    {
        parent::__construct();

        $this->configDir = $_SERVER['HOME'].'/.config/spotify-cli';
        $this->pidFile = $this->configDir.'/daemon.pid';
        $this->credentialsFile = base_path('storage/spotify_token.json');
    }

    public function handle()
    {
        $action = $this->argument('action');

        return match ($action) {
            'start' => $this->start(),
            'stop' => $this->stop(),
            'status' => $this->status(),
            default => $this->invalidAction($action),
        };
    }

    private function start(): int
    {
        // Check if already running
        if ($this->isDaemonRunning()) {
            warning('Daemon is already running');
            info('Use "spotify daemon status" to check status');

            return self::SUCCESS;
        }

        // Check if spotifyd is installed
        $daemonPath = $this->findDaemon();
        if (! $daemonPath) {
            error('spotifyd or librespot not found');
            info('Please install spotifyd:');
            info('  macOS: brew install spotifyd');
            info('  Linux: apt install spotifyd or download from https://github.com/Spotifyd/spotifyd');
            info('');
            info('Or install librespot:');
            info('  cargo install librespot');

            return self::FAILURE;
        }

        // Get Spotify credentials
        $spotify = new SpotifyService;
        if (! $spotify->isConfigured()) {
            error('Spotify not configured');
            info('Run "spotify setup" and "spotify login" first');

            return self::FAILURE;
        }

        // Create config directory if needed
        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0755, true);
        }

        // Determine which daemon we're using
        $daemonType = basename($daemonPath);

        info("Starting {$daemonType} daemon...");

        try {
            $pid = spin(
                callback: fn () => $this->startDaemon($daemonPath, $daemonType),
                message: 'Launching daemon...'
            );

            if ($pid) {
                // Save PID
                file_put_contents($this->pidFile, $pid);

                info("Daemon started successfully (PID: {$pid})");
                info('');
                info('The daemon will appear as a Spotify Connect device.');
                info('Use "spotify devices" to see it and "spotify play" to start playback.');

                // Emit event
                $this->call('event:emit', [
                    'event' => 'daemon.started',
                    'data' => json_encode([
                        'pid' => $pid,
                        'daemon_type' => $daemonType,
                    ]),
                ]);

                return self::SUCCESS;
            } else {
                error('Failed to start daemon');

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            error('Failed to start daemon: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function stop(): int
    {
        if (! $this->isDaemonRunning()) {
            warning('Daemon is not running');

            return self::SUCCESS;
        }

        $pid = (int) file_get_contents($this->pidFile);

        info("Stopping daemon (PID: {$pid})...");

        try {
            // Send SIGTERM to gracefully stop
            posix_kill($pid, SIGTERM);

            // Wait for process to stop (up to 5 seconds)
            $attempts = 0;
            while ($attempts < 10) {
                if (! posix_kill($pid, 0)) {
                    // Process is dead
                    break;
                }
                usleep(500000); // 0.5 seconds
                $attempts++;
            }

            // If still running, force kill
            if (posix_kill($pid, 0)) {
                warning('Daemon did not stop gracefully, forcing...');
                posix_kill($pid, SIGKILL);
            }

            // Remove PID file
            unlink($this->pidFile);

            info('Daemon stopped successfully');

            // Emit event
            $this->call('event:emit', [
                'event' => 'daemon.stopped',
                'data' => json_encode([
                    'pid' => $pid,
                ]),
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            error('Failed to stop daemon: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function status(): int
    {
        $isRunning = $this->isDaemonRunning();

        if ($isRunning) {
            $pid = file_get_contents($this->pidFile);
            info("Daemon is running (PID: {$pid})");

            // Try to get process info
            $processInfo = shell_exec("ps -p {$pid} -o comm=");
            if ($processInfo) {
                info('Process: '.trim($processInfo));
            }

            info('');
            info('Use "spotify devices" to see the daemon device');
            info('Use "spotify daemon stop" to stop the daemon');
        } else {
            warning('Daemon is not running');
            info('Use "spotify daemon start" to start it');
        }

        // Emit event
        $this->call('event:emit', [
            'event' => 'daemon.status_checked',
            'data' => json_encode([
                'is_running' => $isRunning,
                'pid' => $isRunning ? file_get_contents($this->pidFile) : null,
            ]),
        ]);

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        error("Invalid action: {$action}");
        info('Valid actions: start, stop, status');

        return self::FAILURE;
    }

    private function isDaemonRunning(): bool
    {
        if (! file_exists($this->pidFile)) {
            return false;
        }

        $pid = (int) file_get_contents($this->pidFile);

        // Check if process is still running
        // posix_kill with signal 0 checks if process exists without killing it
        if (! posix_kill($pid, 0)) {
            // Process is dead, clean up stale PID file
            unlink($this->pidFile);

            return false;
        }

        return true;
    }

    private function findDaemon(): ?string
    {
        // Try spotifyd first (recommended)
        $spotifyd = shell_exec('which spotifyd 2>/dev/null');
        if ($spotifyd && trim($spotifyd)) {
            return trim($spotifyd);
        }

        // Try librespot as fallback
        $librespot = shell_exec('which librespot 2>/dev/null');
        if ($librespot && trim($librespot)) {
            return trim($librespot);
        }

        return null;
    }

    private function startDaemon(string $daemonPath, string $daemonType): ?int
    {
        // Get credentials from config
        $clientId = config('spotify.client_id');
        $clientSecret = config('spotify.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new \Exception('Spotify credentials not found in config');
        }

        // We need to get username/password or use token
        // For now, we'll start with a basic configuration
        // spotifyd uses a config file, librespot uses command line args

        if ($daemonType === 'spotifyd') {
            return $this->startSpotifyd($daemonPath);
        } elseif ($daemonType === 'librespot') {
            return $this->startLibrespot($daemonPath);
        }

        return null;
    }

    private function startSpotifyd(string $daemonPath): ?int
    {
        // Create spotifyd config file
        $configFile = $this->configDir.'/spotifyd.conf';

        $username = config('spotify.username', '');
        $password = config('spotify.password', '');

        if (! $username || ! $password) {
            throw new \Exception(
                "spotifyd requires username and password.\n".
                'Add SPOTIFY_USERNAME and SPOTIFY_PASSWORD to your .env file'
            );
        }

        $config = <<<EOF
[global]
username = "{$username}"
password = "{$password}"
backend = "pulseaudio"
device_name = "Spotify CLI"
bitrate = 320
cache_path = "{$this->configDir}/cache"
volume_normalisation = true
normalisation_pregain = -10
device_type = "computer"

EOF;

        file_put_contents($configFile, $config);

        // Start spotifyd in background
        $cmd = "{$daemonPath} --config-path {$configFile} --no-daemon > /dev/null 2>&1 & echo $!";
        $pid = (int) shell_exec($cmd);

        // Wait a moment for it to start
        usleep(500000);

        // Verify it started
        if (posix_kill($pid, 0)) {
            return $pid;
        }

        return null;
    }

    private function startLibrespot(string $daemonPath): ?int
    {
        $username = config('spotify.username', '');
        $password = config('spotify.password', '');

        if (! $username || ! $password) {
            throw new \Exception(
                "librespot requires username and password.\n".
                'Add SPOTIFY_USERNAME and SPOTIFY_PASSWORD to your .env file'
            );
        }

        // Start librespot in background
        $cmd = "{$daemonPath} --name 'Spotify CLI' --username '{$username}' --password '{$password}' ".
               "--bitrate 320 --cache {$this->configDir}/cache > /dev/null 2>&1 & echo $!";

        $pid = (int) shell_exec($cmd);

        // Wait a moment for it to start
        usleep(500000);

        // Verify it started
        if (posix_kill($pid, 0)) {
            return $pid;
        }

        return null;
    }
}
