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

    protected $signature = 'daemon {action : start, stop, status, install, or uninstall} {--name= : Device name for Spotify Connect} {--audio-device= : Audio output device (e.g. "Wave Link Stream")}';

    protected $description = 'Manage the Spotify daemon for terminal playback';

    private const LAUNCH_AGENT_LABEL = 'com.theshit.spotifyd';

    private const LEGACY_LAUNCH_AGENT_LABEL = 'com.spotify-cli.spotifyd';

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
        $action = $this->argument('action');

        return match ($action) {
            'start' => $this->start(),
            'stop' => $this->stop(),
            'status' => $this->status(),
            'install' => $this->install(),
            'uninstall' => $this->uninstall(),
            default => $this->invalidAction($action),
        };
    }

    private function start(): int
    {
        $this->cleanupLegacyAgent();

        if ($this->isDaemonRunning()) {
            warning('Daemon is already running');
            info('Use "spotify daemon status" to check status');
            info('Or use: spotify devices to see playback targets');

            return self::SUCCESS;
        }

        // If LaunchAgent is loaded, use launchctl to start
        if ($this->isLaunchAgentLoaded()) {
            shell_exec('launchctl start '.self::LAUNCH_AGENT_LABEL.' 2>&1');
            usleep(2000000);

            if ($this->getDaemonPid()) {
                info('✅ Daemon started via LaunchAgent');

                $deviceName = $this->option('name') ?: 'Work Mac';
                $this->transferPlaybackToDaemon($deviceName);

                return self::SUCCESS;
            }

            warning('LaunchAgent failed to start daemon');
            info('Check logs: ~/.config/spotify-cli/spotifyd.log');
            info('Try: spotify daemon uninstall && spotify daemon install');

            return self::FAILURE;
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

        $spotifyd = $this->findSpotifyd();
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

    private function stop(): int
    {
        // If LaunchAgent is loaded, use launchctl to stop
        if ($this->isLaunchAgentLoaded()) {
            $pid = $this->getDaemonPid();
            if (! $pid) {
                warning('Daemon is not running');

                return self::SUCCESS;
            }

            shell_exec('launchctl stop '.self::LAUNCH_AGENT_LABEL.' 2>&1');
            @unlink($this->pidFile);
            info('✅ Daemon stopped');
            info('Note: KeepAlive is enabled — launchd will restart it automatically.');
            info('To stop permanently: spotify daemon uninstall');

            return self::SUCCESS;
        }

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
        $launchAgent = $this->hasLaunchAgent();
        $running = $this->isDaemonRunning();

        if (! $running && ! $launchAgent) {
            // Also check for LaunchAgent-managed process via pgrep
            $pid = $this->getDaemonPid();
            if ($pid) {
                $running = true;
            }
        }

        if ($running) {
            $pid = $this->getDaemonPid();
            info("✅ Daemon is running (PID: {$pid})");
        } else {
            warning('Daemon is not running');
            info('Use: spotify devices to see available playback devices');
        }

        if ($launchAgent) {
            $loaded = $this->isLaunchAgentLoaded();
            info('📋 LaunchAgent: installed'.($loaded ? ' (loaded)' : ' (not loaded)'));
        }

        return self::SUCCESS;
    }

    private function install(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            error('LaunchAgent is only supported on macOS');
            info('On Linux, use systemd:');
            info('  systemctl --user enable spotifyd');
            info('  systemctl --user start spotifyd');

            return self::FAILURE;
        }

        $this->cleanupLegacyAgent();

        if ($this->hasLaunchAgent()) {
            warning('LaunchAgent is already installed');
            info('Use: spotify daemon uninstall (to reinstall)');

            return self::SUCCESS;
        }

        $spotifyd = $this->findSpotifyd();
        if (! $spotifyd) {
            error('spotifyd not found — run: spotify daemon:setup');

            return self::FAILURE;
        }

        // Ensure spotifyd config exists
        $this->writeSpotifydConfig($spotifyd);

        // Write and load LaunchAgent
        $plistPath = $this->getLaunchAgentPath();
        $plistDir = dirname($plistPath);
        if (! is_dir($plistDir)) {
            mkdir($plistDir, 0755, true);
        }

        file_put_contents($plistPath, $this->generateLaunchAgentPlist($spotifyd));
        shell_exec("launchctl load {$plistPath} 2>&1");

        info('✅ LaunchAgent installed');
        info('Daemon will auto-start on login');

        // Check if it started
        usleep(2000000);
        if ($this->getDaemonPid()) {
            $deviceName = $this->option('name') ?: 'Work Mac';
            info("📱 Daemon started as \"{$deviceName}\"");
        }

        return self::SUCCESS;
    }

    private function uninstall(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            error('LaunchAgent is only supported on macOS');

            return self::FAILURE;
        }

        $plistPath = $this->getLaunchAgentPath();

        if (! file_exists($plistPath)) {
            warning('LaunchAgent is not installed');

            return self::SUCCESS;
        }

        // Stop and unload
        if ($this->isLaunchAgentLoaded()) {
            shell_exec("launchctl unload {$plistPath} 2>&1");
        }

        @unlink($plistPath);
        @unlink($this->pidFile);

        info('✅ LaunchAgent removed');
        info('Daemon will no longer auto-start on login');

        return self::SUCCESS;
    }

    private function findSpotifyd(): ?string
    {
        $rodioPath = ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.local/bin/spotifyd-rodio';
        if (file_exists($rodioPath)) {
            return $rodioPath;
        }

        $which = trim((string) shell_exec('which spotifyd 2>/dev/null'));

        return $which ?: null;
    }

    private function writeSpotifydConfig(string $daemonPath): string
    {
        $configFile = $this->configDir.'/spotifyd.conf';

        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0755, true);
        }

        $deviceName = $this->option('name') ?: 'Work Mac';

        // Detect backend from binary capabilities
        $helpOutput = (string) shell_exec("{$daemonPath} --help 2>&1");
        $backend = str_contains($helpOutput, 'rodio') ? 'rodio' : 'portaudio';

        $config = "[global]\n".
                  "backend = \"{$backend}\"\n".
                  "device_name = \"{$deviceName}\"\n".
                  "bitrate = 320\n".
                  "volume_normalisation = true\n".
                  "cache_path = \"{$this->configDir}/cache\"\n";

        $audioDevice = $this->option('audio-device');
        if ($audioDevice) {
            $config .= "device = \"{$audioDevice}\"\n";
        }

        file_put_contents($configFile, $config);

        return $configFile;
    }

    private function startSpotifyd(string $daemonPath): ?int
    {
        $configFile = $this->writeSpotifydConfig($daemonPath);

        // Start spotifyd
        $logFile = $this->configDir.'/spotifyd.log';
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

    private function getDaemonPid(): ?int
    {
        // Check PID file first
        if (file_exists($this->pidFile)) {
            $pid = (int) file_get_contents($this->pidFile);
            if ($pid > 0 && @posix_kill($pid, 0)) {
                $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
                if ($comm === 'spotifyd') {
                    return $pid;
                }
            }
        }

        // Check for spotifyd using our config (covers LaunchAgent case)
        $configFile = $this->configDir.'/spotifyd.conf';
        $pid = trim((string) shell_exec("pgrep -f 'spotifyd.*{$configFile}' 2>/dev/null | head -1"));
        if ($pid) {
            $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
            if ($comm === 'spotifyd') {
                return (int) $pid;
            }
        }

        return null;
    }

    private function getLaunchAgentPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        return $home.'/Library/LaunchAgents/'.self::LAUNCH_AGENT_LABEL.'.plist';
    }

    private function hasLaunchAgent(): bool
    {
        return file_exists($this->getLaunchAgentPath());
    }

    private function isLaunchAgentLoaded(): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        // Only interact with a LaunchAgent we installed (plist must exist at our expected path)
        if (! $this->hasLaunchAgent()) {
            return false;
        }

        $output = trim((string) shell_exec('launchctl list '.self::LAUNCH_AGENT_LABEL.' 2>&1'));

        return ! empty($output) && ! str_contains($output, 'Could not find');
    }

    private function generateLaunchAgentPlist(string $spotifydPath): string
    {
        $configFile = $this->configDir.'/spotifyd.conf';
        $logFile = $this->configDir.'/spotifyd.log';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.theshit.spotifyd</string>
    <key>ProgramArguments</key>
    <array>
        <string>{$spotifydPath}</string>
        <string>--config-path</string>
        <string>{$configFile}</string>
        <string>--no-daemon</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>ThrottleInterval</key>
    <integer>5</integer>
    <key>StandardOutPath</key>
    <string>{$logFile}</string>
    <key>StandardErrorPath</key>
    <string>{$logFile}</string>
</dict>
</plist>
XML;
    }

    private function cleanupLegacyAgent(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $legacyPlist = $home.'/Library/LaunchAgents/'.self::LEGACY_LAUNCH_AGENT_LABEL.'.plist';

        if (! file_exists($legacyPlist)) {
            return;
        }

        // Unload the old agent if it's loaded
        $output = trim((string) shell_exec('launchctl list '.self::LEGACY_LAUNCH_AGENT_LABEL.' 2>&1'));
        if (! empty($output) && ! str_contains($output, 'Could not find')) {
            shell_exec('launchctl unload '.$legacyPlist.' 2>&1');
        }

        @unlink($legacyPlist);
        info('🧹 Cleaned up legacy LaunchAgent ('.self::LEGACY_LAUNCH_AGENT_LABEL.')');
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
        $this->info('Available actions: start, stop, status, install, uninstall');

        return self::FAILURE;
    }
}
