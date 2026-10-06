<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class DiagnoseCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'diagnose {--json : Output as JSON} {--fix : Attempt to fix issues}';

    protected $description = 'Diagnose Spotify playback issues';

    private string $configDir;

    private const LOG_TAIL_LINES = 100;

    public function __construct()
    {
        parent::__construct();

        $this->configDir = ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.config/spotify-cli';
    }

    public function handle(): int
    {
        $diagnosis = $this->diagnose();

        if ($this->option('json')) {
            $this->line(json_encode($diagnosis));

            return $diagnosis['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        }

        $this->printReport($diagnosis);

        if ($diagnosis['status'] !== 'healthy' && $this->option('fix')) {
            return $this->fixIssues($diagnosis);
        }

        if ($diagnosis['status'] !== 'healthy' && ! $this->option('fix')) {
            info('Run with --fix to attempt automatic repairs: spotify diagnose --fix');
        }

        return $diagnosis['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    private function printReport(array $diagnosis): void
    {
        $statusIcon = match ($diagnosis['status']) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            default => '💀',
        };

        info("{$statusIcon} System status: {$diagnosis['status']}");

        $this->newLine();

        // Process status
        if ($diagnosis['pid']) {
            note("Spotifyd PID: {$diagnosis['pid']}");
        } else {
            warning('Spotifyd is not running');
        }

        // Service status
        if ($diagnosis['service_active']) {
            note('Systemd/LaunchAgent: active');
        } else {
            warning('Systemd/LaunchAgent: not active');
        }

        // Config
        if ($diagnosis['config_exists']) {
            note('Config: present at ~/.config/spotify-cli/spotifyd.conf');
        } else {
            error('Config: missing');
        }

        // Auth
        if ($diagnosis['auth_valid']) {
            note('OAuth token: valid');
        } else {
            warning('OAuth token: missing or invalid');
            info('Run: spotify login');
        }

        // Log errors
        if ($diagnosis['log_errors']) {
            warning('Recent errors in spotifyd.log:');
            foreach ($diagnosis['log_errors'] as $pattern => $count) {
                note("  {$pattern}: {$count}x");
            }
        } else {
            note('Log: no recent errors');
        }

        // Cache size
        if ($diagnosis['cache_size_mb'] !== null) {
            note("Cache size: {$diagnosis['cache_size_mb']} MB");
        }

        // Device availability
        if ($diagnosis['devices']) {
            note('Devices found: '.count($diagnosis['devices']));
            $active = array_filter($diagnosis['devices'], fn ($d) => $d['is_active'] ?? false);
            if ($active) {
                note('Active device: '.current($active)['name']);
            }
        } else {
            warning('No spotifyd devices visible');
        }
    }

    /**
     * @return array{status: string, pid: ?int, service_active: bool, config_exists: bool, auth_valid: bool, log_errors: array<string, int>, cache_size_mb: ?float, devices: array}
     */
    private function diagnose(): array
    {
        $pid = $this->getDaemonPid();
        $serviceActive = $this->isServiceActive();
        $configExists = file_exists($this->configDir.'/spotifyd.conf');
        $authValid = $this->authValid();
        $logErrors = $this->scanLogErrors();
        $cacheSize = $this->getCacheSize();
        $devices = $this->getAvailableDevices();

        // Determine status
        if (! $pid) {
            $status = 'dead';
        } elseif (! $configExists) {
            $status = 'broken';
        } elseif (! $authValid) {
            $status = 'degraded';
        } elseif (count($logErrors) > 5) {
            $status = 'degraded';
        } else {
            $status = 'healthy';
        }

        return [
            'status' => $status,
            'pid' => $pid,
            'service_active' => $serviceActive,
            'config_exists' => $configExists,
            'auth_valid' => $authValid,
            'log_errors' => $logErrors,
            'cache_size_mb' => $cacheSize,
            'devices' => $devices,
        ];
    }

    private function getDaemonPid(): ?int
    {
        $configFile = $this->configDir.'/spotifyd.conf';
        $pid = trim((string) shell_exec("pgrep -f 'spotifyd.*{$configFile}' 2>/dev/null | head -1"));

        if ($pid !== '' && $pid !== '0') {
            $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
            if (stripos(basename($comm), 'spotifyd') !== false) {
                return (int) $pid;
            }
        }

        return null;
    }

    private function isServiceActive(): bool
    {
        if (! \function_exists('shell_exec')) {
            return false;
        }

        try {
            $output = trim((string) shell_exec('systemctl --user is-active spotifyd 2>/dev/null'));

            return $output === 'active';
        } catch (\Exception $e) {
            return false;
        }
    }

    private function authValid(): bool
    {
        $credentialsPath = $this->configDir.'/cache/oauth/credentials.json';

        if (! file_exists($credentialsPath) || is_dir($credentialsPath)) {
            return false;
        }

        $content = file_get_contents($credentialsPath);

        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);

        return $data !== null && isset($data['username']) && isset($data['auth_data']);
    }

    private function scanLogErrors(): array
    {
        $logFile = $this->configDir.'/spotifyd.log';

        if (! file_exists($logFile)) {
            return [];
        }

        $tail = trim((string) shell_exec('tail -n '.self::LOG_TAIL_LINES.' '.escapeshellarg($logFile).' 2>/dev/null'));

        if ($tail === '') {
            return [];
        }

        $lines = explode("\n", $tail);

        $patterns = [
            '400 Bad Request' => 0,
            '401 Unauthorized' => 0,
            '(context is not available)' => 0,
            'failed to play track' => 0,
            'connection refused' => 0,
            'Connection reset' => 0,
            'TLS error' => 0,
        ];

        foreach ($lines as $line) {
            foreach (array_keys($patterns) as $pattern) {
                if (stripos($line, $pattern) !== false) {
                    $patterns[$pattern]++;
                }
            }
        }

        return array_filter($patterns);
    }

    private function getCacheSize(): ?float
    {
        $cachePath = $this->configDir.'/cache';

        if (! is_dir($cachePath)) {
            return null;
        }

        $bytes = trim((string) shell_exec('du -sk '.escapeshellarg($cachePath).' 2>/dev/null | cut -f1'));

        if ($bytes === '' || $bytes === '0') {
            return 0.0;
        }

        return round((int) $bytes / 1024, 1);
    }

    private function getAvailableDevices(): array
    {
        $jsonOutput = (string) shell_exec('cd /home/jordan/Projects/the-shit/music && ./spotify devices --json 2>/dev/null');

        if ($jsonOutput === '') {
            return [];
        }

        try {
            $devices = json_decode($jsonOutput, true);

            return is_array($devices) ? $devices : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function fixIssues(array $diagnosis): int
    {
        info('Attempting to fix issues...');

        if (! $diagnosis['pid']) {
            info('Starting spotifyd...');

            $spotifyd = trim((string) shell_exec('which spotifyd 2>/dev/null'));

            if (! $spotifyd) {
                error('spotifyd not found on PATH');

                return self::FAILURE;
            }

            $configFile = $this->configDir.'/spotifyd.conf';
            $logFile = $this->configDir.'/spotifyd.log';

            $cmd = "{$spotifyd} --config-path {$configFile} --no-daemon --disable-discovery > {$logFile} 2>&1 & echo $!";
            $pid = (int) shell_exec($cmd);

            if ($pid > 0) {
                info('✅ spotifyd started');
            } else {
                error('Failed to start spotifyd');

                return self::FAILURE;
            }
        }

        if (! $diagnosis['auth_valid']) {
            warning('OAuth token is invalid');
            info('Please run: spotify login');

            return self::FAILURE;
        }

        if ($diagnosis['cache_size_mb'] > 500) {
            info('Clearing cache...');

            $cachePath = $this->configDir.'/cache';

            if (is_dir($cachePath)) {
                // Preserve auth directories
                foreach (['oauth', 'credentials.json'] as $preserve) {
                    $path = $cachePath.'/'.$preserve;
                    if (file_exists($path)) {
                        $backup = sys_get_temp_dir().'/spotifyd-'.$preserve.'-'.getmypid();
                        copy($path, $backup);
                    }
                }

                $this->clearDirectory($cachePath);

                foreach (['oauth', 'credentials.json'] as $preserve) {
                    $backup = sys_get_temp_dir().'/spotifyd-'.$preserve.'-'.getmypid();
                    if (file_exists($backup)) {
                        copy($backup, $cachePath.'/'.$preserve);
                        unlink($backup);
                    }
                }

                info('✅ Cache cleared');
            }
        }

        info('✅ Fixes applied');

        return self::SUCCESS;
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
}
