<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\Daemon\AudioBackend;
use App\Services\Daemon\DeviceResolution;
use App\Services\Daemon\HealthStatus;
use App\Services\Daemon\UserUnit;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class DaemonCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'daemon {action? : setup, start, stop, status, health, install, or uninstall} {--name= : Device name for Spotify Connect (defaults to hostname)} {--audio-device= : Audio output device (e.g. "Wave Link Stream")} {--heal : Auto-heal when health check detects issues} {--json : Output health status as JSON}';

    protected $description = 'Manage the Spotify daemon for terminal playback';

    private const LAUNCH_AGENT_LABEL = 'com.theshit.spotifyd';

    private const LEGACY_LAUNCH_AGENT_LABEL = 'com.spotify-cli.spotifyd';

    /**
     * Health scans only this recent window of spotifyd.log — the log is never
     * rotated outside --heal, so it accumulates weeks of history and stale
     * errors that say nothing about the daemon's health right now.
     */
    private const LOG_TAIL_LINES = 500;

    /**
     * Printed by `spotify daemon` with no action. One line each.
     *
     * @var array<string, string>
     */
    private const ACTIONS = [
        'setup' => 'Install spotifyd and authenticate the local speaker',
        'start' => 'Start the local Connect speaker',
        'stop' => 'Stop the local Connect speaker',
        'status' => 'Show whether the speaker is running',
        'health' => 'Check the speaker and its PipeWire sink',
        'install' => 'Enable the user service that keeps the speaker alive',
        'uninstall' => 'Remove the user service',
    ];

    private string $home;

    private string $pidFile;

    private string $configDir;

    private DeviceResolution $deviceResolution;

    public function __construct()
    {
        parent::__construct();

        $this->home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $this->configDir = $this->home.'/.config/spotify-cli';
        $this->pidFile = $this->configDir.'/daemon.pid';
        $this->deviceResolution = new DeviceResolution;
    }

    public function setDeviceResolution(DeviceResolution $deviceResolution): void
    {
        $this->deviceResolution = $deviceResolution;
    }

    public function getDeviceResolution(): DeviceResolution
    {
        return $this->deviceResolution;
    }

    private ?SpotifyAuthManager $auth = null;

    private ?SpotifyPlayerService $player = null;

    /** @var null|callable(): bool */
    private $connectPlayingProbe = null;

    /** @var null|callable(): bool */
    private $sinkInputProbe = null;

    /**
     * @param  null|callable(): bool  $connectPlaying
     * @param  null|callable(): bool  $hasSinkInput
     */
    public function setAudioGraphProbes(?callable $connectPlaying, ?callable $hasSinkInput): void
    {
        $this->connectPlayingProbe = $connectPlaying;
        $this->sinkInputProbe = $hasSinkInput;
    }

    public function handle(SpotifyAuthManager $auth, SpotifyPlayerService $player): int
    {
        $this->auth = $auth;
        $this->player = $player;
        $action = $this->argument('action');

        if (! is_string($action) || $action === '') {
            return $this->catalog();
        }

        return match ($action) {
            'setup' => $this->setup(),
            'start' => $this->start(),
            'stop' => $this->stop(),
            'status' => $this->status(),
            'health' => $this->health(),
            'install' => $this->install(),
            'uninstall' => $this->uninstall(),
            default => $this->invalidAction($action),
        };
    }

    private function catalog(): int
    {
        foreach (self::ACTIONS as $name => $description) {
            $this->line(str_pad($name, 10).$description);
        }

        return self::SUCCESS;
    }

    private function setup(): int
    {
        return $this->call('daemon:setup');
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

                $deviceName = $this->resolveDeviceName();
                $this->transferPlaybackToDaemon($deviceName);

                return self::SUCCESS;
            }

            warning('LaunchAgent failed to start daemon');
            info('Check logs: ~/.config/spotify-cli/spotifyd.log');
            info('Try: spotify daemon uninstall && spotify daemon install');

            return self::FAILURE;
        }

        if ($this->userUnitInstalled()) {
            return $this->startUserUnit();
        }

        // Detect orphaned spotifyd processes using our config
        $configFile = $this->configDir.'/spotifyd.conf';
        $orphanPid = trim((string) shell_exec("pgrep -f 'spotifyd.*{$configFile}' 2>/dev/null | head -1"));
        $orphanComm = $orphanPid !== '' && $orphanPid !== '0' ? trim((string) shell_exec("ps -p {$orphanPid} -o comm= 2>/dev/null")) : '';
        if ($orphanPid && self::isSpotifydComm($orphanComm)) {
            warning("Found orphaned spotifyd (PID: {$orphanPid}) — adopting it");
            $this->savePid((int) $orphanPid);
            info('✅ Daemon adopted');

            $deviceName = $this->resolveDeviceName();
            $this->transferPlaybackToDaemon($deviceName);

            return self::SUCCESS;
        }

        $spotifyd = $this->findSpotifyd();
        if (! $spotifyd) {
            error('spotifyd not found');
            $this->newLine();
            info('To install:');
            info('  macOS: brew install spotifyd');
            info('  Linux: omarchy pkg add spotifyd');
            info('         or: sudo pacman -S spotifyd');
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
            info('3. Try: spotify daemon setup');
            info('');
            info('Alternative: Use existing devices');
            info('  spotify devices');

            return self::FAILURE;
        }

        $this->savePid($pid);
        info('✅ Daemon started');

        $deviceName = $this->resolveDeviceName();
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

        if ($this->userUnitInstalled()) {
            return $this->stopUserUnit();
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

        if ($this->userUnitInstalled()) {
            $active = $this->systemctlUser('is-active '.UserUnit::NAME) === 'active';
            info('📋 User unit: installed'.($active ? ' (active)' : ' (inactive)'));
        }

        return self::SUCCESS;
    }

    private function install(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return $this->installLinux();
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            error('Daemon install supports Linux systemd and macOS LaunchAgent');

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
            error('spotifyd not found — run: spotify daemon setup');

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
            $deviceName = $this->resolveDeviceName();
            info("📱 Daemon started as \"{$deviceName}\"");
        }

        return self::SUCCESS;
    }

    private function uninstall(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return $this->uninstallLinux();
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            error('Daemon install supports Linux systemd and macOS LaunchAgent');

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
        $rodioPath = $this->home.'/.local/bin/spotifyd-rodio';
        if (file_exists($rodioPath)) {
            return $rodioPath;
        }

        $which = trim((string) shell_exec('which spotifyd 2>/dev/null'));

        return $which ?: null;
    }

    private function resolveDeviceName(): string
    {
        return $this->deviceResolution->resolveDaemonName(
            $this->option('name') ?: null,
        );
    }

    public function writeSpotifydConfig(string $daemonPath): string
    {
        $configFile = $this->configDir.'/spotifyd.conf';

        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0755, true);
        }

        $deviceName = $this->resolveDeviceName();
        $existing = is_file($configFile) ? (string) file_get_contents($configFile) : '';
        $helpOutput = '';
        if (PHP_OS_FAMILY !== 'Linux' && ! str_contains($existing, 'backend = "pulseaudio"')) {
            $helpOutput = (string) shell_exec(escapeshellarg($daemonPath).' --help 2>&1');
        }

        $backend = AudioBackend::resolve(PHP_OS_FAMILY, $existing, $helpOutput);

        $config = "[global]\n".
                  "backend = \"{$backend}\"\n".
                  "device_name = \"{$deviceName}\"\n".
                  "bitrate = 320\n".
                  "volume_normalisation = true\n".
                  "cache_path = \"{$this->configDir}/cache\"\n".
                  "credentials_cache = \"{$this->configDir}/cache/oauth\"\n";

        $audioDevice = $this->option('audio-device');
        $deviceLine = AudioBackend::deviceLine(PHP_OS_FAMILY, is_string($audioDevice) ? $audioDevice : null);
        if ($deviceLine !== null) {
            $config .= $deviceLine."\n";
        }

        file_put_contents($configFile, $config);

        return $configFile;
    }

    private function startSpotifyd(string $daemonPath): ?int
    {
        $configFile = $this->writeSpotifydConfig($daemonPath);

        // Start spotifyd
        $logFile = $this->configDir.'/spotifyd.log';
        $cmd = "{$daemonPath} --config-path {$configFile} --no-daemon --disable-discovery > {$logFile} 2>&1 & echo $!";
        $pid = (int) shell_exec($cmd);

        usleep(1500000);

        if ($pid > 0 && posix_kill($pid, 0)) {
            return $pid;
        }

        if (file_exists($logFile)) {
            $log = trim(file_get_contents($logFile));
            if ($log !== '' && $log !== '0') {
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
        if (! self::isSpotifydComm($comm)) {
            @unlink($this->pidFile);

            return false;
        }

        return true;
    }

    /**
     * Whether a `ps -o comm=` value names a spotifyd binary.
     *
     * WHY not `$comm === 'spotifyd'`: on macOS `comm` is the FULL PATH
     * (/Users/x/.local/bin/spotifyd-rodio), and our preferred binary is named
     * `spotifyd-rodio` — so an exact match was ALWAYS false, health reported
     * the daemon dead forever, and the --heal loop never engaged. Public static
     * + pure so the comparison itself is unit-testable.
     */
    public static function isSpotifydComm(string $comm): bool
    {
        return str_starts_with(basename(trim($comm)), 'spotifyd');
    }

    private function getDaemonPid(): ?int
    {
        // Check PID file first
        if (file_exists($this->pidFile)) {
            $pid = (int) file_get_contents($this->pidFile);
            if ($pid > 0 && @posix_kill($pid, 0)) {
                $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
                if (self::isSpotifydComm($comm)) {
                    return $pid;
                }
            }
        }

        // Check for spotifyd using our config (covers LaunchAgent case)
        $configFile = $this->configDir.'/spotifyd.conf';
        $pid = trim((string) shell_exec("pgrep -f 'spotifyd.*{$configFile}' 2>/dev/null | head -1"));
        if ($pid !== '' && $pid !== '0') {
            $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
            if (self::isSpotifydComm($comm)) {
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

        return $output !== '' && $output !== '0' && ! str_contains($output, 'Could not find');
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
        <string>--disable-discovery</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <dict>
        <key>SuccessfulExit</key>
        <false/>
    </dict>
    <key>ThrottleInterval</key>
    <integer>30</integer>
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
        if ($output !== '' && $output !== '0' && ! str_contains($output, 'Could not find')) {
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

            if (! $this->auth instanceof SpotifyAuthManager || ! $this->player instanceof SpotifyPlayerService) {
                return;
            }

            if (! $this->auth->isConfigured()) {
                return;
            }

            // Poll for the daemon device to appear (up to 8 seconds)
            $device = null;
            for ($i = 0; $i < 8; $i++) {
                $devices = $this->player->getDevices();
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

            $this->player->transferPlayback($device['id'], true);
            info("📱 Playback transferred to \"{$deviceName}\"");
        } catch (\Throwable $e) {
            warning('Could not transfer playback: '.$e->getMessage());
            info('📱 Run: spotify devices');
        }
    }

    private function health(): int
    {
        $diagnosis = $this->diagnose();

        if ($this->option('json')) {
            $this->line(json_encode($diagnosis));

            return $diagnosis['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        }

        $statusIcon = match ($diagnosis['status']) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            default => '💀',
        };

        info("{$statusIcon} Daemon status: {$diagnosis['status']}");

        if ($diagnosis['pid']) {
            note("PID: {$diagnosis['pid']}");
        }

        if ($diagnosis['errors']) {
            warning('Recent errors in spotifyd.log:');
            foreach ($diagnosis['errors'] as $pattern => $count) {
                note("  {$pattern}: {$count} occurrences");
            }
        }

        if ($diagnosis['cache_size_mb'] !== null) {
            note("Cache size: {$diagnosis['cache_size_mb']} MB");
        }

        if ($diagnosis['playing_without_sink']) {
            warning('Connect says this device is playing, but spotifyd has no sink-input');
        }

        if ($diagnosis['status'] !== 'healthy' && $this->option('heal')) {
            return $this->heal($diagnosis);
        }

        if ($diagnosis['status'] !== 'healthy' && ! $this->option('heal')) {
            info('Run with --heal to auto-fix: spotify daemon health --heal');
        }

        return $diagnosis['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Diagnose daemon health by checking process status and log errors.
     *
     * @return array{status: string, pid: int|null, errors: array<string, int>, cache_size_mb: float|null, log_lines: int, playing_without_sink: bool}
     */
    public function diagnose(): array
    {
        $pid = $this->getDaemonPid();
        $logFile = $this->configDir.'/spotifyd.log';
        $cachePath = $this->configDir.'/cache';

        $errors = $this->scanLogErrors($logFile);
        $totalErrors = array_sum($errors);
        $cacheSize = $this->getCacheSizeMb($cachePath);
        $playingWithoutSink = $pid !== null && $this->playingWithoutSinkInput();

        return [
            'status' => HealthStatus::resolve($pid, $totalErrors, $cacheSize, $playingWithoutSink),
            'pid' => $pid,
            'errors' => $errors,
            'cache_size_mb' => $cacheSize,
            'log_lines' => $this->countLogLines($logFile),
            'playing_without_sink' => $playingWithoutSink,
        ];
    }

    /**
     * Count log lines without loading the file into memory — count(file())
     * materialised every line of a multi-million-line log just to count them.
     */
    private function countLogLines(string $logFile): int
    {
        if (! file_exists($logFile)) {
            return 0;
        }

        return (int) trim((string) shell_exec('wc -l < '.escapeshellarg($logFile).' 2>/dev/null'));
    }

    private function heal(array $diagnosis): int
    {
        info('Healing daemon...');

        if (($diagnosis['playing_without_sink'] ?? false) === true) {
            note('Connect is playing but spotifyd has no sink-input. Restarting so the stream comes back.');

            return $this->restartSpeaker(
                AudioBackend::logShowsDeviceNotAvailable($this->recentLog()),
                is_int($diagnosis['pid'] ?? null) ? $diagnosis['pid'] : null,
            );
        }

        $deviceUnavailable = AudioBackend::logShowsDeviceNotAvailable($this->recentLog());

        // 1. Clear audio cache (preserve auth directories and credentials)
        $cachePath = $this->configDir.'/cache';
        if (is_dir($cachePath)) {
            $preserveDirs = ['oauth', 'zeroconf'];
            $preserved = [];

            // Back up auth directories and credentials.json
            foreach ($preserveDirs as $dir) {
                $dirPath = $cachePath.'/'.$dir;
                if (is_dir($dirPath)) {
                    $backupPath = sys_get_temp_dir().'/spotifyd-heal-'.$dir.'-'.getmypid();
                    shell_exec('cp -a '.escapeshellarg($dirPath).' '.escapeshellarg($backupPath).' 2>/dev/null');
                    $preserved[$dir] = $backupPath;
                }
            }

            $credentialsPath = $cachePath.'/credentials.json';
            $savedCredentials = file_exists($credentialsPath) ? file_get_contents($credentialsPath) : null;

            $this->clearDirectory($cachePath);

            // Restore auth directories
            foreach ($preserved as $dir => $backupPath) {
                $restorePath = $cachePath.'/'.$dir;
                shell_exec('cp -a '.escapeshellarg($backupPath).' '.escapeshellarg($restorePath).' 2>/dev/null');
                shell_exec('rm -rf '.escapeshellarg($backupPath).' 2>/dev/null');
            }

            if ($savedCredentials) {
                file_put_contents($credentialsPath, $savedCredentials);
            }

            $authCount = count($preserved) + ($savedCredentials ? 1 : 0);
            if ($authCount > 0) {
                note("Preserved {$authCount} auth artifact(s)");
            }

            info('Cleared audio cache');
        }

        // 2. Rotate the log file
        $logFile = $this->configDir.'/spotifyd.log';
        if (file_exists($logFile)) {
            $rotated = $logFile.'.'.date('Ymd-His');
            rename($logFile, $rotated);
            info("Rotated log to {$rotated}");
        }

        // 3. Restart the daemon
        if ($diagnosis['pid']) {
            info('Restarting daemon...');

            return $this->restartSpeaker(
                $deviceUnavailable,
                is_int($diagnosis['pid']) ? $diagnosis['pid'] : null,
            );
        }

        // Daemon was dead — try starting fresh
        return $this->start();
    }

    /**
     * Scan log file for known error patterns.
     *
     * @return array<string, int>
     */
    private function scanLogErrors(string $logFile): array
    {
        if (! file_exists($logFile)) {
            return [];
        }

        // "context is not available" is a single-track WARN (no album context).
        // Counting it marks every solo play degraded and heal restarts the speaker.
        $patterns = [
            'out of range integral' => 0,
            'failed to handle request' => 0,
            'Invalid start position' => 0,
            'connection refused' => 0,
            'Connection reset without closing handshake' => 0,
            'TLS error' => 0,
            'Connection to server closed' => 0,
            'IncompleteMessage' => 0,
            'failed to put connect state' => 0,
        ];

        // Scan ONLY a recent tail window. file() loaded the ENTIRE log into
        // memory first (weeks of history, millions of lines) just to slice the
        // end off — and any error counted from months-old lines is meaningless
        // for "is the daemon healthy right now". tail streams the last lines
        // without reading the rest.
        $tail = (string) shell_exec('tail -n '.self::LOG_TAIL_LINES.' '.escapeshellarg($logFile).' 2>/dev/null');
        $lines = $tail === '' ? [] : explode("\n", $tail);

        foreach ($lines as $line) {
            foreach (array_keys($patterns) as $pattern) {
                if (stripos($line, $pattern) !== false) {
                    $patterns[$pattern]++;
                }
            }
        }

        return array_filter($patterns);
    }

    private function getCacheSizeMb(string $path): ?float
    {
        if (! is_dir($path)) {
            return null;
        }

        $bytes = trim((string) shell_exec('du -sk '.escapeshellarg($path).' 2>/dev/null | cut -f1'));

        if ($bytes === '' || $bytes === '0') {
            return 0.0;
        }

        return round((int) $bytes / 1024, 1);
    }

    private function clearDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    private function invalidAction(string $action): int
    {
        error("Invalid action: {$action}");
        info('Available actions: '.implode(', ', array_keys(self::ACTIONS)));

        return self::FAILURE;
    }

    private function installLinux(): int
    {
        $spotifyd = $this->findSpotifyd();
        if (! $spotifyd) {
            error('spotifyd not found — run: spotify daemon setup');

            return self::FAILURE;
        }

        $this->noteDeviceUnavailable();
        $this->writeSpotifydConfig($spotifyd);

        $unit = new UserUnit;
        $path = $unit->path($this->home);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $unit->contents($spotifyd));
        info('Wrote '.$path);

        if (! $this->enableAndStartUserUnit()) {
            error('User unit was written but is not active');
            info('Check: systemctl --user status '.UserUnit::NAME);

            return self::FAILURE;
        }

        info('✅ User unit installed');
        $deviceName = $this->resolveDeviceName();
        info("📱 Daemon started as \"{$deviceName}\"");

        return self::SUCCESS;
    }

    private function uninstallLinux(): int
    {
        $path = (new UserUnit)->path($this->home);
        if (! is_file($path)) {
            warning('User unit is not installed');

            return self::SUCCESS;
        }

        $this->systemctlUser('disable --now '.UserUnit::NAME);
        @unlink($path);
        $this->systemctlUser('daemon-reload');
        @unlink($this->pidFile);

        info('✅ User unit removed');
        info('Daemon will no longer start with the session');

        return self::SUCCESS;
    }

    private function startUserUnit(): int
    {
        $spotifyd = $this->findSpotifyd();
        if ($spotifyd) {
            $this->noteDeviceUnavailable();
            $this->writeSpotifydConfig($spotifyd);
        }

        $this->systemctlUser('start '.UserUnit::NAME);

        if ($this->systemctlUser('is-active '.UserUnit::NAME) === 'active' || $this->getDaemonPid()) {
            info('✅ Daemon started via user unit');
            $this->transferPlaybackToDaemon($this->resolveDeviceName());

            return self::SUCCESS;
        }

        error('User unit failed to start');
        info('Check: systemctl --user status '.UserUnit::NAME);
        info('Log: ~/.config/spotify-cli/spotifyd.log');

        return self::FAILURE;
    }

    private function stopUserUnit(): int
    {
        $active = $this->systemctlUser('is-active '.UserUnit::NAME) === 'active';
        if (! $active && ! $this->isDaemonRunning() && $this->getDaemonPid() === null) {
            warning('Daemon is not running');

            return self::SUCCESS;
        }

        $this->systemctlUser('stop '.UserUnit::NAME);
        @unlink($this->pidFile);
        info('✅ Daemon stopped');
        info('The user unit stays installed. Remove it with: spotify daemon uninstall');

        return self::SUCCESS;
    }

    private function userUnitInstalled(): bool
    {
        return is_file((new UserUnit)->path($this->home));
    }

    private function enableAndStartUserUnit(): bool
    {
        $this->systemctlUser('daemon-reload');
        $this->systemctlUser('enable --now '.UserUnit::NAME);

        return $this->systemctlUser('is-active '.UserUnit::NAME) === 'active';
    }

    private function systemctlUser(string $arguments): string
    {
        return trim((string) shell_exec('systemctl --user '.$arguments.' 2>/dev/null'));
    }

    /**
     * @param  bool  $deviceUnavailable  Log already showed librespot could not open the card.
     */
    private function restartSpeaker(bool $deviceUnavailable, ?int $pid): int
    {
        $spotifyd = $this->findSpotifyd();
        if ($spotifyd !== null && (PHP_OS_FAMILY === 'Linux' || $deviceUnavailable)) {
            if ($deviceUnavailable) {
                note('Audio device unavailable — rewriting backend to pulseaudio');
            }
            $this->writeSpotifydConfig($spotifyd);
        }

        if ($this->userUnitInstalled()) {
            $this->systemctlUser('restart '.UserUnit::NAME);
            if ($this->systemctlUser('is-active '.UserUnit::NAME) === 'active') {
                info('Daemon restarted successfully');
                $this->transferPlaybackToDaemon($this->resolveDeviceName());

                return self::SUCCESS;
            }

            error('Daemon failed to restart');

            return self::FAILURE;
        }

        if ($this->isLaunchAgentLoaded()) {
            shell_exec('launchctl stop '.self::LAUNCH_AGENT_LABEL.' 2>&1');
            sleep(2);
            shell_exec('launchctl start '.self::LAUNCH_AGENT_LABEL.' 2>&1');
        } elseif ($pid) {
            posix_kill($pid, SIGTERM);
            sleep(2);

            if (@posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                sleep(1);
            }

            if ($spotifyd) {
                $newPid = $this->startSpotifyd($spotifyd);
                if ($newPid) {
                    $this->savePid($newPid);
                }
            }
        } elseif ($spotifyd) {
            $newPid = $this->startSpotifyd($spotifyd);
            if ($newPid) {
                $this->savePid($newPid);
            }
        }

        sleep(2);
        if ($this->getDaemonPid()) {
            info('Daemon restarted successfully');
            $this->transferPlaybackToDaemon($this->resolveDeviceName());

            return self::SUCCESS;
        }

        error('Daemon failed to restart');

        return self::FAILURE;
    }

    private function noteDeviceUnavailable(): void
    {
        if (AudioBackend::logShowsDeviceNotAvailable($this->recentLog())) {
            note('Audio device unavailable — rewriting backend to pulseaudio');
        }
    }

    private function recentLog(): string
    {
        $logFile = $this->configDir.'/spotifyd.log';
        if (! is_file($logFile)) {
            return '';
        }

        return (string) shell_exec('tail -n '.self::LOG_TAIL_LINES.' '.escapeshellarg($logFile).' 2>/dev/null');
    }

    private function playingWithoutSinkInput(): bool
    {
        if ($this->connectPlayingProbe === null && PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        return $this->connectSaysThisDeviceIsPlaying() && ! $this->spotifydSinkInputPresent();
    }

    private function connectSaysThisDeviceIsPlaying(): bool
    {
        $probe = $this->connectPlayingProbe;
        if ($probe !== null) {
            return $probe();
        }

        if (! $this->auth instanceof SpotifyAuthManager || ! $this->player instanceof SpotifyPlayerService) {
            return false;
        }

        try {
            if (! $this->auth->isConfigured()) {
                return false;
            }

            $playback = $this->player->getCurrentPlayback();
            if (! is_array($playback) || ($playback['is_playing'] ?? false) !== true) {
                return false;
            }

            $device = $playback['device'] ?? null;
            $deviceName = is_array($device) ? ($device['name'] ?? null) : null;
            if (! is_string($deviceName) || $deviceName === '') {
                return false;
            }

            return $deviceName === $this->resolveDeviceName();
        } catch (\Throwable) {
            return false;
        }
    }

    private function spotifydSinkInputPresent(): bool
    {
        $probe = $this->sinkInputProbe;
        if ($probe !== null) {
            return $probe();
        }

        $dump = [];
        $exitCode = 1;
        exec('pactl list sink-inputs 2>/dev/null', $dump, $exitCode);
        if ($exitCode !== 0) {
            $dump = [];
            exec('pactl list short sink-inputs 2>/dev/null', $dump, $exitCode);
        }

        // No pactl (or it cannot talk to the session) is not a missing stream.
        if ($exitCode !== 0) {
            return true;
        }

        return str_contains(strtolower(implode("\n", $dump)), 'spotifyd');
    }
}
