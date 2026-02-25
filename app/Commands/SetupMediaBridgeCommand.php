<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class SetupMediaBridgeCommand extends Command
{
    protected $signature = 'setup:media-bridge
        {--uninstall : Remove the media bridge LaunchAgent}
        {--status : Check media bridge status}';

    protected $description = 'Set up the Swift media bridge for macOS Control Center and media keys';

    private const LAUNCH_AGENT_LABEL = 'com.theshit.media-bridge';

    private string $configDir;

    private string $binDir;

    public function __construct()
    {
        parent::__construct();

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $this->configDir = $home.'/.config/spotify-cli';
        $this->binDir = $home.'/.local/bin';
    }

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            error('Media bridge is only supported on macOS');

            return self::FAILURE;
        }

        if ($this->option('uninstall')) {
            return $this->uninstall();
        }

        if ($this->option('status')) {
            return $this->status();
        }

        return $this->install();
    }

    private function install(): int
    {
        $this->banner();

        // Step 1: Check for swiftc
        info('📦 Checking dependencies...');

        $swiftc = trim((string) shell_exec('which swiftc 2>/dev/null'));
        if (! $swiftc) {
            error('swiftc not found — install Xcode Command Line Tools:');
            info('  xcode-select --install');

            return self::FAILURE;
        }
        info('✅ swiftc found');

        // Step 2: Check for the Swift source
        $swiftFile = $this->resolveSwiftSource();
        if (! $swiftFile) {
            error('media-bridge.swift not found');
            info('Expected at: helpers/media-bridge.swift (relative to project root)');

            return self::FAILURE;
        }
        info("✅ Swift source: {$swiftFile}");

        // Step 3: Unload existing bridge if running
        $this->unloadExisting();

        // Step 4: Compile
        $outputBin = $this->binDir.'/spotify-media-bridge';

        if (! is_dir($this->binDir)) {
            mkdir($this->binDir, 0755, true);
        }

        info('🔨 Compiling Swift media bridge...');
        $this->newLine();

        $compileCmd = "{$swiftc} -O -o ".escapeshellarg($outputBin).' '.escapeshellarg($swiftFile).' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($compileCmd, $output, $exitCode);

        if ($exitCode !== 0) {
            error('Compilation failed:');
            foreach ($output as $line) {
                $this->line("  {$line}");
            }

            return self::FAILURE;
        }

        chmod($outputBin, 0755);
        info("✅ Compiled to {$outputBin}");

        // Step 5: Write launchd plist
        $plistPath = $this->getLaunchAgentPath();
        $plistDir = dirname($plistPath);
        if (! is_dir($plistDir)) {
            mkdir($plistDir, 0755, true);
        }

        $logFile = $this->configDir.'/media-bridge.log';
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $plist = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.theshit.media-bridge</string>

    <key>ProgramArguments</key>
    <array>
        <string>{$outputBin}</string>
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

    <key>EnvironmentVariables</key>
    <dict>
        <key>HOME</key>
        <string>{$home}</string>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>
</dict>
</plist>
XML;

        file_put_contents($plistPath, $plist);
        info("✅ LaunchAgent written to {$plistPath}");

        // Step 6: Load the daemon
        shell_exec('launchctl load '.escapeshellarg($plistPath).' 2>&1');
        usleep(2000000);

        // Step 7: Verify it's running
        $pid = $this->getBridgePid();
        if ($pid) {
            info("✅ Media bridge running (PID: {$pid})");
        } else {
            warning('Bridge loaded but not yet running — check logs:');
            info("  tail -f {$logFile}");
        }

        // Step 8: Remove old Python bridge if present
        $this->removeOldPythonBridge();

        $this->displaySuccess($logFile);

        return self::SUCCESS;
    }

    private function uninstall(): int
    {
        $plistPath = $this->getLaunchAgentPath();

        if (! file_exists($plistPath)) {
            warning('Media bridge LaunchAgent is not installed');

            return self::SUCCESS;
        }

        $this->unloadExisting();
        @unlink($plistPath);

        info('✅ Media bridge LaunchAgent removed');
        info('Bridge will no longer auto-start on login');

        if (confirm('Also remove compiled binary?', false)) {
            $bin = $this->binDir.'/spotify-media-bridge';
            @unlink($bin);
            info('✅ Binary removed');
        }

        return self::SUCCESS;
    }

    private function status(): int
    {
        $plistPath = $this->getLaunchAgentPath();
        $installed = file_exists($plistPath);
        $pid = $this->getBridgePid();
        $logFile = $this->configDir.'/media-bridge.log';

        $this->newLine();
        info('🎵 Media Bridge Status');
        $this->newLine();

        if ($pid) {
            info("✅ Running (PID: {$pid})");
        } else {
            warning('❌ Not running');
        }

        if ($installed) {
            $loaded = $this->isLaunchAgentLoaded();
            info('📋 LaunchAgent: installed'.($loaded ? ' (loaded)' : ' (not loaded)'));
        } else {
            warning('📋 LaunchAgent: not installed');
        }

        $bin = $this->binDir.'/spotify-media-bridge';
        if (file_exists($bin)) {
            info("📦 Binary: {$bin}");
        } else {
            warning('📦 Binary: not compiled');
        }

        if (file_exists($logFile)) {
            $this->newLine();
            info('📝 Recent logs:');
            $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES), -5);
            foreach ($lines as $line) {
                $this->line("  {$line}");
            }
        }

        return self::SUCCESS;
    }

    // -- Helpers --

    private function resolveSwiftSource(): ?string
    {
        // Try project-relative path first (works in dev)
        $candidates = [
            base_path('helpers/media-bridge.swift'),
            dirname(__DIR__, 2).'/helpers/media-bridge.swift',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function unloadExisting(): void
    {
        $plistPath = $this->getLaunchAgentPath();

        if (file_exists($plistPath) && $this->isLaunchAgentLoaded()) {
            shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>&1');
            usleep(500000);
            info('🔄 Unloaded existing media bridge');
        }

        // Also unload the old Python-based nowplaying agent if present
        $oldPlist = ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/Library/LaunchAgents/com.spotify-cli.nowplaying.plist';
        if (file_exists($oldPlist)) {
            shell_exec('launchctl unload '.escapeshellarg($oldPlist).' 2>&1');
            info('🔄 Unloaded old Python nowplaying bridge');
        }
    }

    private function removeOldPythonBridge(): void
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $oldPlist = $home.'/Library/LaunchAgents/com.spotify-cli.nowplaying.plist';

        if (file_exists($oldPlist)) {
            if (confirm('Remove old Python nowplaying LaunchAgent?', true)) {
                @unlink($oldPlist);
                info('✅ Old Python bridge LaunchAgent removed');
            }
        }
    }

    private function getLaunchAgentPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        return $home.'/Library/LaunchAgents/'.self::LAUNCH_AGENT_LABEL.'.plist';
    }

    private function getBridgePid(): ?int
    {
        $pid = trim((string) shell_exec("pgrep -f 'spotify-media-bridge' 2>/dev/null | head -1"));

        return $pid ? (int) $pid : null;
    }

    private function isLaunchAgentLoaded(): bool
    {
        $output = trim((string) shell_exec('launchctl list '.self::LAUNCH_AGENT_LABEL.' 2>&1'));

        return ! empty($output) && ! str_contains($output, 'Could not find');
    }

    private function banner(): void
    {
        $this->newLine();
        $this->line('  ╔═══════════════════════════════════════════╗');
        $this->line('  ║  🎵 Swift Media Bridge Setup              ║');
        $this->line('  ╚═══════════════════════════════════════════╝');
        $this->newLine();
    }

    private function displaySuccess(string $logFile): void
    {
        $this->newLine();
        $this->line('  ╔═══════════════════════════════════════════╗');
        $this->line('  ║  ✅ Media Bridge Installed!               ║');
        $this->line('  ╚═══════════════════════════════════════════╝');
        $this->newLine();

        info('Media keys and Control Center now control Spotify via CLI.');
        $this->newLine();
        info('Commands:');
        info('  spotify setup:media-bridge --status    # check status');
        info('  spotify setup:media-bridge --uninstall # remove');
        $this->newLine();
        info("Logs: tail -f {$logFile}");
    }
}
