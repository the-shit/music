<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use LaravelZero\Framework\Commands\Command;

class NowPlayingCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'nowplaying
        {--interval=3 : Polling interval in seconds}
        {--stop : Kill any running nowplaying bridge}';

    protected $description = 'Sync playback to macOS Control Center and enable media keys';

    public function handle(): int
    {
        if ($this->option('stop')) {
            return $this->stopBridge();
        }

        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $scriptDir = dirname(__DIR__, 2).'/bin';
        $bridgeScript = $scriptDir.'/nowplaying-bridge.py';
        $spotifyBin = dirname(__DIR__, 2).'/spotify';
        $interval = max(2, (int) $this->option('interval'));

        if (! file_exists($bridgeScript)) {
            $this->error("Bridge script not found: {$bridgeScript}");

            return self::FAILURE;
        }

        $python = trim(shell_exec('which python3') ?: '');
        if (! $python) {
            $this->error('python3 not found — required for nowplaying bridge.');

            return self::FAILURE;
        }

        $this->info('Starting macOS NowPlaying bridge...');
        $this->line('Media keys and Control Center widget will reflect Spotify playback.');
        $this->line('Ctrl+C to stop.');
        $this->newLine();

        // Pipe `spotify watch --json` into the bridge script
        $cmd = sprintf(
            '%s watch --json --interval=%d | %s %s',
            escapeshellarg($spotifyBin),
            $interval,
            escapeshellarg($python),
            escapeshellarg($bridgeScript)
        );

        passthru($cmd, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function stopBridge(): int
    {
        exec("pkill -f 'nowplaying-bridge.py'", $output, $code);

        if ($code === 0) {
            $this->info('NowPlaying bridge stopped.');
        } else {
            $this->warn('No running nowplaying bridge found.');
        }

        return self::SUCCESS;
    }
}
