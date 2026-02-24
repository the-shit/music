<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DaemonCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'daemon {action : start, stop, or status} {--name= : Device name for Spotify Connect}';

    protected $description = 'Manage the Spotify daemon for terminal playback (experimental)';

    private string $pidFile;

    private string $configDir;

    public function __construct()
    {
        parent::__construct();

        $this->configDir = ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.config/spotify-cli';
        $this->pidFile = $this->configDir.'/daemon.pid';
    }

    public function handle()
    {
        warning('⚠️  Daemon mode is experimental and finicky on macOS');
        warning('💡 Try using existing devices instead:');
        warning('   spotify play "song" --device="Your Device Name"');
        warning('   spotify devices  # list available devices');
        $this->newLine();

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
        if ($this->isDaemonRunning()) {
            warning('Daemon is already running');
            info('Use "spotify daemon status" to check status');
            info('Or use: spotify devices to see playback targets');

            return self::SUCCESS;
        }

        // Detect orphaned spotifyd processes using our config
        $configFile = $this->configDir.'/spotifyd.conf';
        $orphanPid = trim((string) shell_exec("pgrep -f 'spotifyd.*{$configFile}' 2>/dev/null | head -1"));
        $orphanComm = $orphanPid ? trim((string) shell_exec("ps -p {$orphanPid} -o comm= 2>/dev/null")) : '';
        if ($orphanPid && $orphanComm === 'spotifyd') {
            warning("Found orphaned spotifyd (PID: {$orphanPid}) — adopting it");
            $this->savePid((int) $orphanPid);
            info('✅ Daemon adopted');

            $deviceName = $this->option('name') ?: 'Work Mac';
            $this->transferPlaybackToDaemon($deviceName);

            return self::SUCCESS;
        }

        // Prefer rodio build over portaudio (better audio buffering)
        $rodioPath = ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.local/bin/spotifyd-rodio';
        $spotifyd = file_exists($rodioPath) ? $rodioPath : trim((string) shell_exec('which spotifyd 2>/dev/null'));
        if (! $spotifyd) {
            error('spotifyd not found');
            $this->newLine();
            info('To install:');
            info('  macOS: brew install spotifyd');
            info('  Linux: apt install spotifyd');
            info('');
            info('Or use existing devices instead:');
            info('  spotify devices  # list available devices');
            info('  spotify play "song" --device="Your Device"');

            return self::FAILURE;
        }

        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        info('🚀 Starting spotifyd...');
        $pid = $this->startSpotifyd($spotifyd);

        if (! $pid) {
            error('Failed to start daemon');
            $this->newLine();
            info('Troubleshooting:');
            info('1. Make sure spotifyd is properly authenticated');
            info('2. Check ~/.config/spotify-cli/spotifyd.log for errors');
            info('3. Try running: spotifyd authenticate');
            info('');
            info('Alternative: Use existing devices');
            info('  spotify devices');

            return self::FAILURE;
        }

        $this->savePid($pid);
        info('✅ Daemon started');

        $deviceName = $this->option('name') ?: 'Work Mac';
        $this->transferPlaybackToDaemon($deviceName);

        return self::SUCCESS;
    }

    private function startSpotifyd(string $daemonPath): ?int
    {
        $configDir = $this->configDir;
        $configFile = $configDir.'/spotifyd.conf';

        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $deviceName = $this->option('name') ?: 'Work Mac';

        // Detect backend from binary capabilities
        $helpOutput = (string) shell_exec("{$daemonPath} --help 2>&1");
        $backend = str_contains($helpOutput, 'rodio') ? 'rodio' : 'portaudio';

        // Configuration — uses OAuth via cache_path, no username needed
        $config = "[global]\n".
                  "backend = \"{$backend}\"\n".
                  "device_name = \"{$deviceName}\"\n".
                  "bitrate = 320\n".
                  "volume_normalisation = true\n".
                  "cache_path = \"{$configDir}/cache\"\n";

        file_put_contents($configFile, $config);

        // Start spotifyd
        $logFile = $configDir.'/spotifyd.log';
        $cmd = "{$daemonPath} --config-path {$configFile} --no-daemon > {$logFile} 2>&1 & echo $!";
        $pid = (int) shell_exec($cmd);

        usleep(1500000);

        if ($pid > 0 && posix_kill($pid, 0)) {
            return $pid;
        }

        if (file_exists($logFile)) {
            $log = trim(file_get_contents($logFile));
            if ($log) {
                warning('Daemon error:');
                $this->line($log);
            }
        }

        return null;
    }

    private function stop(): int
    {
        if (! $this->isDaemonRunning()) {
            warning('Daemon is not running');

            return self::SUCCESS;
        }

        $pid = (int) file_get_contents($this->pidFile);
        posix_kill($pid, SIGTERM);

        $waited = 0;
        while (posix_getpgid($pid) !== false && $waited < 5) {
            sleep(1);
            $waited++;
        }

        if (posix_getpgid($pid) !== false) {
            posix_kill($pid, SIGKILL);
        }

        @unlink($this->pidFile);
        info('✅ Daemon stopped');

        return self::SUCCESS;
    }

    private function status(): int
    {
        if (! $this->isDaemonRunning()) {
            warning('Daemon is not running');
            info('Use: spotify devices to see available playback devices');

            return self::SUCCESS;
        }

        $pid = file_get_contents($this->pidFile);
        info("✅ Daemon is running (PID: {$pid})");

        return self::SUCCESS;
    }

    private function isDaemonRunning(): bool
    {
        if (! file_exists($this->pidFile)) {
            return false;
        }

        $pid = (int) file_get_contents($this->pidFile);

        if ($pid <= 0 || ! posix_kill($pid, 0)) {
            @unlink($this->pidFile);

            return false;
        }

        // Verify the PID is actually spotifyd, not a recycled process
        $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
        if ($comm !== 'spotifyd') {
            @unlink($this->pidFile);

            return false;
        }

        return true;
    }

    private function savePid(int $pid): void
    {
        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0755, true);
        }

        file_put_contents($this->pidFile, $pid);
        chmod($this->pidFile, 0600);
    }

    private function transferPlaybackToDaemon(string $deviceName): void
    {
        try {
            $spotify = app(SpotifyService::class);

            if (! $spotify->isConfigured()) {
                return;
            }

            // Poll for the daemon device to appear (up to 8 seconds)
            $device = null;
            for ($i = 0; $i < 8; $i++) {
                $devices = $spotify->getDevices();
                foreach ($devices as $d) {
                    if (($d['name'] ?? '') === $deviceName) {
                        $device = $d;
                        break 2;
                    }
                }
                sleep(1);
            }

            if (! $device) {
                warning("Device \"{$deviceName}\" not yet visible to Spotify — run: spotify devices");

                return;
            }

            $spotify->transferPlayback($device['id'], true);
            info("📱 Playback transferred to \"{$deviceName}\"");
        } catch (\Throwable $e) {
            warning('Could not transfer playback: '.$e->getMessage());
            info('📱 Run: spotify devices');
        }
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->info('Available actions: start, stop, status');

        return self::FAILURE;
    }
}
