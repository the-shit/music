<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Daemon\Config;
use App\Services\Daemon\DeviceResolution;
use App\Services\Daemon\Provision;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Termwind\render;
use function Termwind\renderUsing;

class DaemonSetupCommand extends Command
{
    protected $signature = 'daemon:setup';

    protected $description = 'Set up the Spotify daemon with all dependencies';

    private Provision $provision;

    private Config $config;

    private DeviceResolution $deviceResolution;

    public function __construct()
    {
        parent::__construct();

        $this->provision = new Provision;
        $this->config = new Config;
        $this->deviceResolution = new DeviceResolution;
    }

    public function handle(): int
    {
        $this->banner();

        if (($result = $this->checkDependencies()) !== null) {
            return $result;
        }

        if (($result = $this->authenticateSpotifyd()) !== null) {
            return $result;
        }

        if (($result = $this->startDaemon()) !== null) {
            return $result;
        }

        $this->displaySuccess();

        return self::SUCCESS;
    }

    private function banner(): void
    {
        $this->title('Spotify daemon setup');

        renderUsing($this->output);
        render('<div class="ml-1 mb-1 text-gray-400">Headless Connect speaker. No desktop app.</div>');
    }

    private function checkDependencies(): ?int
    {
        $soxOk = $this->task('Checking sox', function (): bool {
            $sox = trim((string) shell_exec('which play 2>/dev/null'));

            return $sox !== '' && $sox !== '0';
        });

        $spotifydOk = $this->task('Checking spotifyd', function (): bool {
            return $this->provision->findSpotifyd() !== null;
        });

        if ($soxOk && $spotifydOk) {
            return null;
        }

        $issues = [];
        if (! $soxOk) {
            $issues[] = 'sox';
        }
        if (! $spotifydOk) {
            $issues[] = 'spotifyd';
        }

        if (! confirm('Install missing dependencies now?', true)) {
            $this->error('Setup cancelled. Install dependencies manually:');
            $this->line('  macOS: brew install '.implode(' ', $issues));
            $this->line('  Linux: apt install '.implode(' ', $issues));

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
            $this->error("Unsupported OS: {$os}");

            return self::FAILURE;
        }

        $this->info("Running: {$cmd}");
        passthru($cmd);

        $ok = $this->task('Verifying dependencies', function () use ($issues): bool {
            foreach ($issues as $dep) {
                if ($dep === 'sox') {
                    $check = trim((string) shell_exec('which play 2>/dev/null'));
                    if ($check === '' || $check === '0') {
                        return false;
                    }

                    continue;
                }

                if ($this->provision->findSpotifyd() === null) {
                    return false;
                }
            }

            return true;
        });

        if (! $ok) {
            $this->error('Failed to install dependencies');

            return self::FAILURE;
        }

        return null;
    }

    private function authenticateSpotifyd(): ?int
    {
        $cachePath = $this->config->cachePath();
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        if ($this->config->hasOauthCredentials()) {
            $this->task('Already authenticated with Spotify', fn (): bool => true);

            return null;
        }

        $spotifyd = $this->provision->findSpotifyd();
        if ($spotifyd === null) {
            $this->error('Could not locate spotifyd binary');

            return self::FAILURE;
        }

        $this->info('A browser window will open. Approve Spotify, then come back.');

        $ok = $this->task('Waiting for Spotify login', function () use ($spotifyd, $cachePath): bool {
            return $this->waitForOauth($spotifyd, $cachePath);
        });

        if (! $ok) {
            $this->error('Authentication failed');

            return self::FAILURE;
        }

        return null;
    }

    private function waitForOauth(string $spotifyd, string $cachePath): bool
    {
        $command = [
            $spotifyd,
            'authenticate',
            '--cache-path',
            $cachePath,
        ];

        if ($this->config->exists()) {
            $command[] = '--config-path';
            $command[] = $this->config->configPath();
        }

        $process = Process::timeout(180)->start($command);
        $oauthFile = $this->config->oauthCredentialsPath();

        while ($process->running()) {
            if (is_file($oauthFile) && filesize($oauthFile) > 0) {
                $process->stop();

                return true;
            }

            usleep(200000);
        }

        return is_file($oauthFile) && filesize($oauthFile) > 0;
    }

    private function startDaemon(): ?int
    {
        $name = $this->deviceResolution->resolveDaemonName();

        $ok = $this->task("Starting daemon as {$name}", function () use ($name): bool {
            return $this->callSilent('daemon', [
                'action' => 'start',
                '--name' => $name,
            ]) === self::SUCCESS;
        });

        if (! $ok) {
            $this->error('Daemon failed to start. Check ~/.config/spotify-cli/spotifyd.log');

            return self::FAILURE;
        }

        return null;
    }

    private function displaySuccess(): void
    {
        $name = $this->deviceResolution->resolveDaemonName();

        renderUsing($this->output);
        render(<<<HTML
<div class="mt-1">
    <div class="px-1 bg-green-600 text-white">Setup complete</div>
    <div class="ml-1 mt-1 text-gray-400">spotify daemon start --name="{$name}"</div>
    <div class="ml-1 text-gray-400">spotify play "a song" --device="{$name}"</div>
    <div class="ml-1 text-gray-400">spotify daemon stop</div>
</div>
HTML);
    }
}
