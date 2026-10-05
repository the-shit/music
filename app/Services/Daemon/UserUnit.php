<?php

declare(strict_types=1);

namespace App\Services\Daemon;

/**
 * systemd --user unit that keeps spotifyd on the same config as the CLI
 * and brings it back when PipeWire restarts.
 *
 * PartOf= (not BindsTo=) is the restart hook. BindsTo= also refuses to stay
 * up unless PipeWire is already active, which races the login transaction
 * and respawns the rodio "device not available" panic. PartOf= propagates
 * stop/restart from pipewire.service and pipewire-pulse.service, which is
 * what `systemctl --user restart pipewire` does, and Restart= covers a
 * crash that is not a PipeWire bounce.
 */
class UserUnit
{
    public const NAME = 'spotifyd.service';

    public function path(?string $home = null): string
    {
        $home ??= $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        return $home.'/.config/systemd/user/'.self::NAME;
    }

    public function contents(string $spotifydPath): string
    {
        $binary = $this->quoteExec($spotifydPath);
        $exec = $binary.' --config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery';

        return <<<UNIT
[Unit]
Description=spotifyd Connect speaker for spotify-cli
After=pipewire.service pipewire-pulse.service
PartOf=pipewire.service pipewire-pulse.service
StartLimitBurst=5
StartLimitIntervalSec=60

[Service]
ExecStart={$exec}
Restart=always
RestartSec=2
StandardOutput=append:%h/.config/spotify-cli/spotifyd.log
StandardError=append:%h/.config/spotify-cli/spotifyd.log

[Install]
WantedBy=default.target
UNIT;
    }

    private function quoteExec(string $path): string
    {
        if ($path !== '' && preg_match('/^[A-Za-z0-9_@\\/.:-]+$/', $path) === 1) {
            return $path;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $path).'"';
    }
}
