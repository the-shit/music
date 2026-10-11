<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Daemon\Packages;
use App\Services\Daemon\Provision;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DaemonSetupCommand extends Command
{
    protected $signature = 'daemon:setup';

    protected $description = 'Set up the Spotify daemon (use: spotify daemon setup)';

    protected $hidden = true;

    /** @var null|callable(string, string): void */
    private $authenticateRunner = null;

    /**
     * @param  callable(string, string): void  $runner
     */
    public function setAuthenticateRunner(callable $runner): void
    {
        $this->authenticateRunner = $runner;
    }

    public function oauthCredentialsPath(): string
    {
        return $this->cachePath().'/oauth/credentials.json';
    }

    public function hasOauthCredentials(): bool
    {
        return is_file($this->oauthCredentialsPath());
    }

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

        if ($this->startDaemon() !== self::SUCCESS) {
            return self::FAILURE;
        }

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

        $sox = trim((string) shell_exec('which play 2>/dev/null'));
        if ($sox === '' || $sox === '0') {
            warning('❌ sox not found (required for audio playback)');
            $issues[] = 'sox';
        } else {
            info('✅ sox installed');
        }

        if ((new Provision)->findSpotifyd() === null) {
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
            $this->printInstallHints($issues);

            return self::FAILURE;
        }

        return $this->installDependencies($issues);
    }

    /**
     * @param  list<string>  $issues
     */
    private function printInstallHints(array $issues): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            info('  Linux: omarchy pkg add '.implode(' ', $issues));
            info('         or: sudo pacman -S --needed '.implode(' ', $issues));

            return;
        }

        $command = Packages::installCommand(PHP_OS_FAMILY, $issues, false);
        if ($command !== null) {
            info('  '.$command);
        }
    }

    /**
     * @param  list<string>  $issues
     */
    private function installDependencies(array $issues): ?int
    {
        $cmd = Packages::installCommand(PHP_OS_FAMILY, $issues, $this->omarchyAvailable());
        if ($cmd === null) {
            error('Unsupported OS: '.PHP_OS_FAMILY);

            return self::FAILURE;
        }

        info("Running: {$cmd}");
        $this->newLine();

        passthru($cmd);

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

        $cachePath = $this->cachePath();
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        // spotifyd 0.4 writes <cache_path>/oauth/credentials.json.
        // cache/credentials.json and cache/zeroconf/credentials.json are not that login.
        if ($this->hasOauthCredentials()) {
            info('✅ Already authenticated with Spotify');

            return null;
        }

        $spotifyd = (new Provision)->findSpotifyd();
        if ($spotifyd === null) {
            error('spotifyd not found');

            return self::FAILURE;
        }

        warning('Log in to Spotify in the browser. This waits until the local speaker is authenticated.');
        info('A leftover zeroconf session is not a login.');
        $this->newLine();

        $this->runSpotifydAuthenticate($spotifyd, $cachePath);

        if ($this->hasOauthCredentials()) {
            info('✅ Spotify authentication successful!');

            return null;
        }

        error('❌ Authentication failed');
        info('The speaker is authenticated only when cache/oauth/credentials.json exists.');

        return self::FAILURE;
    }

    private function runSpotifydAuthenticate(string $spotifyd, string $cachePath): void
    {
        $runner = $this->authenticateRunner;
        if ($runner !== null) {
            $runner($spotifyd, $cachePath);

            return;
        }

        $oauthFile = $cachePath.'/oauth/credentials.json';
        $oauthDir = dirname($oauthFile);
        if (! is_dir($oauthDir)) {
            mkdir($oauthDir, 0700, true);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$spotifyd, 'authenticate', '--cache-path', $cachePath],
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            return;
        }

        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $opened = false;
        $buffer = '';
        $deadline = time() + 180;

        while (time() < $deadline) {
            $chunk = (string) fread($pipes[1], 8192);
            $chunk .= (string) fread($pipes[2], 8192);
            if ($chunk !== '') {
                $buffer .= $chunk;
                if (! $opened && preg_match('#https://\\S+#', $buffer, $matches) === 1) {
                    $this->openBrowser($matches[0]);
                    $opened = true;
                }
            }

            if (is_file($oauthFile)) {
                break;
            }

            $status = proc_get_status($process);
            if (! $status['running'] && $chunk === '') {
                break;
            }

            usleep(200000);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_file($oauthFile)) {
            proc_terminate($process);
        }

        proc_close($process);
    }

    private function openBrowser(string $url): void
    {
        $opener = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        shell_exec($opener.' '.escapeshellarg($url).' >/dev/null 2>&1 &');
    }

    private function startDaemon(): int
    {
        $this->newLine();

        if (PHP_OS_FAMILY === 'Linux') {
            info('🚀 Enabling the systemd user unit...');
            $this->newLine();

            return $this->call('daemon', ['action' => 'install']);
        }

        info('🚀 Starting Spotify daemon...');
        $this->newLine();

        return $this->call('daemon', ['action' => 'start']);
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

    private function cachePath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        return $home.'/.config/spotify-cli/cache';
    }

    private function omarchyAvailable(): bool
    {
        $bin = trim((string) shell_exec('command -v omarchy 2>/dev/null'));

        return $bin !== '' && $bin !== '0';
    }
}
