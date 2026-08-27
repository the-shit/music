<?php

declare(strict_types=1);

use App\Services\Daemon\Process;

describe('Daemon Process', function (): void {

    it('is dead when there is no pid file and no matching spotifyd', function (): void {
        $home = sys_get_temp_dir().'/spotify-process-'.uniqid();
        mkdir($home.'/.config/spotify-cli', 0755, true);

        expect((new Process)->isAlive($home))->toBeFalse();

        rmdir($home.'/.config/spotify-cli');
        rmdir($home.'/.config');
        rmdir($home);
    });

    it('is dead when the pid file points at a recycled non-spotifyd process', function (): void {
        $home = sys_get_temp_dir().'/spotify-process-'.uniqid();
        mkdir($home.'/.config/spotify-cli', 0755, true);
        file_put_contents($home.'/.config/spotify-cli/daemon.pid', (string) getmypid());

        expect((new Process)->isAlive($home))->toBeFalse();

        unlink($home.'/.config/spotify-cli/daemon.pid');
        rmdir($home.'/.config/spotify-cli');
        rmdir($home.'/.config');
        rmdir($home);
    });

});
