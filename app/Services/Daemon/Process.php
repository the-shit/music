<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Commands\DaemonCommand;

class Process
{
    /**
     * Whether a spotifyd using this machine's spotify-cli config is alive.
     *
     * Connect can still list the speaker after the process is killed.
     * Play uses this to raise that zombie, not the Connect roster.
     */
    public function isAlive(?string $home = null): bool
    {
        $home ??= getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
        $configDir = $home.'/.config/spotify-cli';
        $pidFile = $configDir.'/daemon.pid';
        $configFile = $configDir.'/spotifyd.conf';

        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            if ($pid > 0 && @posix_kill($pid, 0)) {
                $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
                if (DaemonCommand::isSpotifydComm($comm)) {
                    return true;
                }
            }
        }

        $pid = trim((string) shell_exec("pgrep -f 'spotifyd.*{$configFile}' 2>/dev/null | head -1"));
        if ($pid !== '' && $pid !== '0') {
            $comm = trim((string) shell_exec("ps -p {$pid} -o comm= 2>/dev/null"));
            if (DaemonCommand::isSpotifydComm($comm)) {
                return true;
            }
        }

        return false;
    }
}
