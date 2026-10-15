<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class NowPlayingCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'nowplaying
        {--interval=3 : Polling interval in seconds (ignored; use setup:media-bridge)}
        {--stop : Stop the Swift media bridge LaunchAgent}';

    protected $description = 'Deprecated: use setup:media-bridge for macOS Control Center and media keys';

    public function handle(): int
    {
        if ($this->option('stop')) {
            return $this->stopBridge();
        }

        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            error('Media keys / Control Center sync is only supported on macOS.');
            note('On Linux, see specs/mpris/ for the planned MPRIS bridge.');

            return self::FAILURE;
        }

        warning('`spotify nowplaying` is deprecated.');
        info('The Python nowplaying bridge was replaced by the Swift media bridge.');
        note('Run: spotify setup:media-bridge');
        note('Status: spotify setup:media-bridge --status');

        return self::FAILURE;
    }

    private function stopBridge(): int
    {
        // Prefer the current Swift LaunchAgent; also clear any leftover Python bridge.
        exec("pkill -f 'nowplaying-bridge.py' 2>/dev/null");

        $exitCode = $this->call('setup:media-bridge', ['--uninstall' => true]);

        if ($exitCode === self::SUCCESS) {
            info('Media bridge stopped.');

            return self::SUCCESS;
        }

        warning('No running media bridge found. Try: spotify setup:media-bridge --status');

        return self::SUCCESS;
    }
}
