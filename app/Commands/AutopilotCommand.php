<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class AutopilotCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'autopilot
        {--threshold=3 : Refill when queue has fewer than N tracks}
        {--mood=flow : Mood for recommendations (chill/flow/hype)}
        {--interval=3 : Watch polling interval in seconds}
        {--install : Install autopilot as a background LaunchAgent}
        {--uninstall : Remove the autopilot LaunchAgent}
        {--status : Check autopilot daemon status}';

    protected $description = 'Auto-refill the queue on every track change (event-driven)';

    private const LAUNCH_AGENT_LABEL = 'com.theshit.autopilot';

    /** @var array<string, int> URI => timestamp when queued */
    private array $sessionUriTimestamps = [];

    private ?string $lastRefillTime = null;

    /** Tracks older than this many seconds can be re-queued */
    private const DEDUP_WINDOW_SECONDS = 1800; // 30 minutes

    public function handle(): int
    {
        if ($this->option('install')) {
            return $this->installDaemon();
        }

        if ($this->option('uninstall')) {
            return $this->uninstallDaemon();
        }

        if ($this->option('status')) {
            return $this->daemonStatus();
        }

        return $this->runAutopilot();
    }

    private function runAutopilot(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $spotify = app(SpotifyService::class);
        $threshold = max(1, (int) $this->option('threshold'));
        $mood = $this->option('mood');
        $interval = max(1, (int) $this->option('interval'));

        if (! in_array($mood, ['chill', 'flow', 'hype'])) {
            warning("Unknown mood '{$mood}' — using 'flow'");
            $mood = 'flow';
        }

        info("Autopilot engaged — mood: {$mood}, threshold: {$threshold}");
        info('Listening for track changes — Ctrl+C to stop');
        $this->newLine();

        // Open a pipe to `watch --json` so we get one JSON line per event
        $php = PHP_BINARY;
        $self = $_SERVER['argv'][0] ?? 'spotify';
        $cmd = escapeshellarg($php).' '.escapeshellarg($self)
                   .' watch --json --interval='.escapeshellarg((string) $interval);

        $pipe = popen($cmd, 'r');

        if (! $pipe) {
            $this->error('Failed to open watch pipe.');

            return self::FAILURE;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($pipe) {
                pclose($pipe);
                $this->newLine();
                info('Autopilot disengaged.');
                exit(0);
            });
        }

        while (! feof($pipe)) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $line = fgets($pipe);

            if ($line === false || trim($line) === '') {
                continue;
            }

            $event = json_decode(trim($line), true);

            if (! is_array($event)) {
                continue;
            }

            // Only act on track changes
            if (($event['type'] ?? null) !== 'track_changed') {
                continue;
            }

            $this->line("Track changed: <fg=cyan>{$event['track']}</> by {$event['artist']}");

            try {
                $this->maybeRefill($spotify, $threshold, $mood);
            } catch (\Exception $e) {
                warning("Refill error: {$e->getMessage()}");
            }
        }

        pclose($pipe);

        return self::SUCCESS;
    }

    // ── Daemon management ──────────────────────────────────────────

    private function installDaemon(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('LaunchAgent is only supported on macOS');

            return self::FAILURE;
        }

        $threshold = $this->option('threshold');
        $mood = $this->option('mood');
        $interval = $this->option('interval');

        // Unload existing if present
        $plistPath = $this->getLaunchAgentPath();
        if (file_exists($plistPath)) {
            shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>&1');
            usleep(500000);
            info('🔄 Unloaded existing autopilot');
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $configDir = $home.'/.config/spotify-cli';
        $logFile = $configDir.'/autopilot.log';

        // Resolve PHP and the spotify CLI binary
        $phpBin = PHP_BINARY;
        $spotifyCli = realpath($_SERVER['argv'][0] ?? base_path('spotify')) ?: base_path('spotify');

        $plist = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.theshit.autopilot</string>

    <key>ProgramArguments</key>
    <array>
        <string>{$phpBin}</string>
        <string>{$spotifyCli}</string>
        <string>autopilot</string>
        <string>--threshold={$threshold}</string>
        <string>--mood={$mood}</string>
        <string>--interval={$interval}</string>
    </array>

    <key>RunAtLoad</key>
    <false/>

    <key>KeepAlive</key>
    <true/>

    <key>ThrottleInterval</key>
    <integer>10</integer>

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

        $plistDir = dirname($plistPath);
        if (! is_dir($plistDir)) {
            mkdir($plistDir, 0755, true);
        }

        file_put_contents($plistPath, $plist);
        shell_exec('launchctl load '.escapeshellarg($plistPath).' 2>&1');
        shell_exec('launchctl start '.self::LAUNCH_AGENT_LABEL.' 2>&1');

        usleep(2000000);

        $pid = $this->getAutopilotPid();

        $this->newLine();
        $this->line('  ╔═══════════════════════════════════════════╗');
        $this->line('  ║  ✅ Autopilot Daemon Installed!           ║');
        $this->line('  ╚═══════════════════════════════════════════╝');
        $this->newLine();

        if ($pid) {
            info("✅ Running (PID: {$pid}) — mood: {$mood}, threshold: {$threshold}");
        } else {
            warning('Loaded but not yet running — may be waiting for playback');
        }

        info("Logs: tail -f {$logFile}");
        $this->newLine();
        info('Commands:');
        info('  spotify autopilot --status');
        info('  spotify autopilot --uninstall');
        $this->newLine();
        info('Note: RunAtLoad is off — autopilot starts on demand via launchctl start');
        info('  launchctl start '.self::LAUNCH_AGENT_LABEL);

        return self::SUCCESS;
    }

    private function uninstallDaemon(): int
    {
        $plistPath = $this->getLaunchAgentPath();

        if (! file_exists($plistPath)) {
            warning('Autopilot LaunchAgent is not installed');

            return self::SUCCESS;
        }

        if ($this->isLaunchAgentLoaded()) {
            shell_exec('launchctl unload '.escapeshellarg($plistPath).' 2>&1');
        }

        @unlink($plistPath);

        info('✅ Autopilot LaunchAgent removed');

        if (confirm('Also clear autopilot log?', false)) {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
            @unlink($home.'/.config/spotify-cli/autopilot.log');
            info('✅ Log cleared');
        }

        return self::SUCCESS;
    }

    private function daemonStatus(): int
    {
        $plistPath = $this->getLaunchAgentPath();
        $installed = file_exists($plistPath);
        $pid = $this->getAutopilotPid();

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $logFile = $home.'/.config/spotify-cli/autopilot.log';

        $this->newLine();
        info('🤖 Autopilot Status');
        $this->newLine();

        if ($pid) {
            info("✅ Running (PID: {$pid})");
        } else {
            warning('❌ Not running');
        }

        if ($installed) {
            $loaded = $this->isLaunchAgentLoaded();
            info('📋 LaunchAgent: installed'.($loaded ? ' (loaded)' : ' (not loaded)'));

            // Show configured mood/threshold from plist
            $plistContent = file_get_contents($plistPath);
            if (preg_match('/--mood=(\w+)/', $plistContent, $m)) {
                info("🎵 Mood: {$m[1]}");
            }
            if (preg_match('/--threshold=(\d+)/', $plistContent, $m)) {
                info("📊 Threshold: {$m[1]}");
            }
        } else {
            warning('📋 LaunchAgent: not installed');
            info('Install with: spotify autopilot --install');
        }

        if (file_exists($logFile)) {
            $this->newLine();
            info('📝 Recent logs:');
            $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -8);
            foreach ($lines as $line) {
                $this->line("  {$line}");
            }
        }

        return self::SUCCESS;
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function getLaunchAgentPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        return $home.'/Library/LaunchAgents/'.self::LAUNCH_AGENT_LABEL.'.plist';
    }

    private function getAutopilotPid(): ?int
    {
        $pid = trim((string) shell_exec("pgrep -f 'spotify.*autopilot' 2>/dev/null | head -1"));

        return $pid ? (int) $pid : null;
    }

    private function isLaunchAgentLoaded(): bool
    {
        $output = trim((string) shell_exec('launchctl list '.self::LAUNCH_AGENT_LABEL.' 2>&1'));

        return ! empty($output) && ! str_contains($output, 'Could not find');
    }

    // ── Queue logic ───────────────────────────────────────────────

    private function maybeRefill(SpotifyService $spotify, int $threshold, string $mood): void
    {
        $queueData = $spotify->getQueue();
        $queue = $queueData['queue'] ?? [];
        $queueDepth = count($queue);
        $current = $spotify->getCurrentPlayback();

        $this->line("  Queue depth: {$queueDepth} / threshold: {$threshold}");

        if ($queueDepth >= $threshold) {
            $this->line('  <fg=gray>Queue healthy — no refill needed</>');

            return;
        }

        $this->refill($spotify, $current ?? [], $queue, $threshold, $mood);
    }

    private function refill(SpotifyService $spotify, array $current, array $queue, int $threshold, string $mood): void
    {
        $needed = $threshold - count($queue);

        // Expire old entries from the dedup window so tracks can be re-queued
        $cutoff = time() - self::DEDUP_WINDOW_SECONDS;
        $this->sessionUriTimestamps = array_filter(
            $this->sessionUriTimestamps,
            fn (int $ts) => $ts > $cutoff
        );

        // Build seed from current track
        $seedTrackIds = [];
        $seedArtistIds = [];

        if (isset($current['uri']) && preg_match('/spotify:track:(.+)/', $current['uri'], $m)) {
            $seedTrackIds[] = $m[1];
        }

        // Add mood-specific audio feature targets if the recommendations API supports it
        $moodParams = match ($mood) {
            'chill' => ['target_energy' => 0.3, 'target_valence' => 0.5, 'target_tempo' => 90],
            'hype' => ['target_energy' => 0.9, 'target_valence' => 0.8, 'target_tempo' => 140],
            default => ['target_energy' => 0.6, 'target_valence' => 0.6, 'target_tempo' => 120], // flow
        };

        // Seed from recent history for variety
        $recentlyPlayed = $spotify->getRecentlyPlayed(5);
        foreach ($recentlyPlayed as $recent) {
            if (count($seedTrackIds) >= 3) {
                break;
            }
            if (preg_match('/spotify:track:(.+)/', $recent['uri'], $m)) {
                if (! in_array($m[1], $seedTrackIds)) {
                    $seedTrackIds[] = $m[1];
                }
            }
        }

        // Build the dedup set — time-bounded session URIs + current context
        $excludeUris = array_fill_keys(array_keys($this->sessionUriTimestamps), true);

        if (isset($current['uri'])) {
            $excludeUris[$current['uri']] = true;
        }
        foreach ($queue as $item) {
            $excludeUris[$item['uri'] ?? ''] = true;
        }
        foreach ($recentlyPlayed as $recent) {
            $excludeUris[$recent['uri']] = true;
        }

        // getRecommendations now auto-falls through to getSmartRecommendations
        // when the deprecated endpoint returns empty
        $currentArtist = $current['artist'] ?? null;
        $recommendations = $spotify->getRecommendations($seedTrackIds, $seedArtistIds, $needed + 10, $moodParams);

        // If still empty (unlikely now), fall back to improved related tracks
        if (empty($recommendations) && $currentArtist) {
            $this->line('  <fg=gray>Smart discovery empty — falling back to related tracks</>');
            $recommendations = $spotify->getRelatedTracks($currentArtist, $current['name'] ?? '', $needed + 10);
        }

        $added = 0;
        foreach ($recommendations as $track) {
            if ($added >= $needed) {
                break;
            }

            if (isset($excludeUris[$track['uri']])) {
                continue;
            }

            try {
                $spotify->addToQueue($track['uri']);
                $this->sessionUriTimestamps[$track['uri']] = time();
                $excludeUris[$track['uri']] = true;
                $added++;
                $this->line("  Queued: <fg=green>{$track['name']}</> by {$track['artist']}");
            } catch (\Exception) {
                continue;
            }
        }

        if ($added > 0) {
            $this->lastRefillTime = now()->format('H:i:s');
            info("Refilled {$added} tracks ({$mood} mood) at {$this->lastRefillTime}");
        } else {
            warning('No fresh recommendations available to add');
        }
    }
}
