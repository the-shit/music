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

        $bridgeScript = $this->resolveBinFile('nowplaying-bridge.py');
        $spotifyBin = $this->resolveSpotifyBin();
        $interval = max(2, (int) $this->option('interval'));

        if (! $bridgeScript || ! file_exists($bridgeScript)) {
            $this->error('Bridge script not found: nowplaying-bridge.py');

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

    /**
     * Resolve a file from the bin/ directory, extracting from PHAR if needed.
     *
     * Inside a PHAR, external processes (python3, bash) cannot read files at
     * phar:// URIs, so we copy the file to a temp directory first.
     */
    private function resolveBinFile(string $filename): ?string
    {
        $pharPath = \Phar::running(false);

        if ($pharPath !== '') {
            // Running inside a PHAR — extract to temp
            $pharBinPath = \Phar::running(true).'/bin/'.$filename;

            if (! file_exists($pharBinPath)) {
                return null;
            }

            $tempDir = sys_get_temp_dir().'/spotify-cli-bin';
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempFile = $tempDir.'/'.$filename;

            // Re-extract if missing or PHAR is newer than cached copy
            if (! file_exists($tempFile) || filemtime($pharPath) > filemtime($tempFile)) {
                copy($pharBinPath, $tempFile);
                chmod($tempFile, 0755);
            }

            return $tempFile;
        }

        // Not in a PHAR — use the file directly from the project tree
        $path = dirname(__DIR__, 2).'/bin/'.$filename;

        return file_exists($path) ? $path : null;
    }

    /**
     * Resolve the spotify binary path.
     *
     * Inside a PHAR the binary IS the PHAR file itself.
     * Outside a PHAR it's the `spotify` script at the project root.
     */
    private function resolveSpotifyBin(): string
    {
        $pharPath = \Phar::running(false);

        if ($pharPath !== '') {
            return $pharPath;
        }

        return dirname(__DIR__, 2).'/spotify';
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
