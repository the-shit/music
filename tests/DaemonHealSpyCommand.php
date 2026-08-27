<?php

namespace Tests;

use App\Commands\DaemonCommand;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use Illuminate\Contracts\Foundation\Application;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DaemonHealSpyCommand extends DaemonCommand
{
    /**
     * @var array<int, array{action: string|null, heal: bool}>
     */
    public static array $calls = [];

    public function handle(SpotifyAuthManager $auth, SpotifyPlayerService $player): int
    {
        self::$calls[] = [
            'action' => $this->argument('action'),
            'heal' => (bool) $this->option('heal'),
        ];

        return self::SUCCESS;
    }

    public static function isolateHome(): string
    {
        $stale = '/tmp/spotify-cli-test/.config/spotify-cli/spotifyd.conf';
        if (is_file($stale)) {
            @unlink($stale);
        }

        $tempDir = sys_get_temp_dir().'/spotify-play-heal-'.uniqid();
        mkdir($tempDir.'/.config/spotify-cli', 0755, true);
        putenv('HOME='.$tempDir);
        $_SERVER['HOME'] = $tempDir;

        return $tempDir;
    }

    public static function restoreHome(string $previous, string $tempDir): void
    {
        putenv('HOME='.$previous);
        $_SERVER['HOME'] = $previous;

        if (! is_dir($tempDir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($tempDir);
    }

    public static function bind(Application $app): self
    {
        self::$calls = [];
        $spy = new self;
        $app->forgetInstance(DaemonCommand::class);
        $app->instance(DaemonCommand::class, $spy);
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand($spy);

        return $spy;
    }

    public static function writeConf(string $home, string $deviceName): void
    {
        file_put_contents(
            $home.'/.config/spotify-cli/spotifyd.conf',
            "[global]\ndevice_name = \"{$deviceName}\"\n"
        );
    }
}
