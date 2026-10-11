<?php

declare(strict_types=1);

use App\Services\Daemon\UserUnit;

describe('spotifyd user unit', function (): void {
    it('pins the cli config, disables discovery, and restarts with pipewire', function (): void {
        $unit = (new UserUnit)->contents('/usr/bin/spotifyd');

        expect($unit)->toContain('ExecStart=/usr/bin/spotifyd --config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery');
        expect($unit)->toContain('After=pipewire.service pipewire-pulse.service');
        expect($unit)->toContain('PartOf=pipewire.service pipewire-pulse.service');
        expect($unit)->toContain('Restart=always');
        expect($unit)->toContain('RestartSec=2');
        expect($unit)->toContain('StartLimitBurst=5');
        expect($unit)->toContain('StartLimitIntervalSec=60');
        expect($unit)->toContain('WantedBy=default.target');
        expect($unit)->not->toContain('hw:');
    });

    it('writes under the user systemd directory', function (): void {
        expect((new UserUnit)->path('/home/demo'))->toBe('/home/demo/.config/systemd/user/spotifyd.service');
    });
});
