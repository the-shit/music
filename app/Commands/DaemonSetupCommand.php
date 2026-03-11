<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DaemonSetupCommand extends Command
{
    protected $signature = 'daemon:setup';

    protected $description = 'Set up the Spotify daemon with all dependencies';

    public function handle(): int
    {
        $this->banner();

        info('This will set up the headless Spotify daemon for CLI playback.');
        $this->newLine();

        if (($result = $this->checkDependencies()) !== null) {
            return $result;
        }

        if (($result = $this->authenticateSpotifyd()) !== null) {
            return $result;
        }

        $this->startDaemon();

        $this->displaySuccess();

        return self::SUCCESS;
    }

    private function banner(): void
    {
        $this->newLine();
        $this->line('  ╔═══════════════════════════════════════════╗');
        $this->line('  ║     🎵 Spotify Daemon Setup               ║');
        $this->line('  ╚═══════════════════════════════════════════╝');
        $this->newLine();
    }

    private function checkDependencies(): ?int
    {
        info('📦 Checking dependencies...');
        $this->newLine();

        $issues = [];

        // Check sox
        $sox = trim((string) shell_exec('which play 2>/dev/null'));
        if ($sox === '' || $sox === '0') {
            warning('❌ sox not found (required for audio playback)');
            $issues[] = 'sox';
        } else {
            info('✅ sox installed');
        }

        // Check spotifyd
        $spotifyd = trim((string) shell_exec('which spotifyd 2>/dev/null'));
        if ($spotifyd === '' || $spotifyd === '0') {
            warning('❌ spotifyd not found (required for Spotify Connect)');
            $issues[] = 'spotifyd';
        } else {
            info('✅ spotifyd installed');
        }

        if ($issues === []) {
            return null;
        }

        $this->newLine();
        $install = confirm('Install missing dependencies now?', true);

        if (! $install) {
            error('Setup cancelled. Install dependencies manually:');
            info('  macOS: brew install spotifyd sox');
            info('  Linux: apt install spotifyd sox');

            return self::FAILURE;
        }

        return $this->installDependencies($issues);
    }

    private function installDependencies(array $issues): ?int
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $cmd = 'brew install '.implode(' ', $issues);
        } elseif ($os === 'Linux') {
            $cmd = 'sudo apt install -y '.implode(' ', $issues);
        } else {
            error("Unsupported OS: {$os}");

            return self::FAILURE;
        }

        info("Running: {$cmd}");
        $this->newLine();

        passthru($cmd);

        // Verify
        foreach ($issues as $dep) {
            if ($dep === 'sox') {
                $check = trim((string) shell_exec('which play 2>/dev/null'));
            } else {
                $check = trim((string) shell_exec('which '.$dep.' 2>/dev/null'));
            }

            if ($check === '' || $check === '0') {
                error("❌ Failed to install {$dep}");

                return self::FAILURE;
            }
        }

        info('✅ All dependencies installed');

        return null;
    }

    private function authenticateSpotifyd(): ?int
    {
        $this->newLine();
        info('🔐 Setting up Spotify authentication...');
        $this->newLine();

        $cachePath = ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.config/spotify-cli/cache';
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        // Check if already authenticated
        $credFile = $cachePath.'/credentials.json';
        if (file_exists($credFile)) {
            info('✅ Already authenticated with Spotify');

            return null;
        }

        warning('You will be asked to authenticate with Spotify in your browser.');
        info('After authenticating, return here.');
        $this->newLine();

        $spotifyd = trim((string) shell_exec('which spotifyd')) ?: '/opt/homebrew/opt/spotifyd/bin/spotifyd';

        passthru("{$spotifyd} authenticate --cache-path {$cachePath}");

        if (file_exists($credFile)) {
            info('✅ Spotify authentication successful!');

            return null;
        }

        error('❌ Authentication failed');

        return self::FAILURE;
    }

    private function startDaemon(): void
    {
        $this->newLine();
        info('🚀 Starting Spotify daemon...');
        $this->newLine();

        $this->call('daemon', ['action' => 'start']);
    }

    private function displaySuccess(): void
    {
        $this->newLine();
        $this->line('  ╔═══════════════════════════════════════════╗');
        $this->line('  ║     ✅ Setup Complete!                    ║');
        $this->line('  ╚═══════════════════════════════════════════╝');
        $this->newLine();

        info('Usage:');
        info('  spotify daemon start --name="My Device"');
        info('  spotify play "song name" --device="My Device"');
        info('  spotify daemon stop');
        $this->newLine();
    }
}
