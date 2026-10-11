<?php

declare(strict_types=1);

namespace App\Services\Daemon;

class AudioBackend
{
    /**
     * Arch talks to PipeWire through pulseaudio. rodio opens hw:0,0 and
     * panics once PipeWire already owns the card. A config that already
     * says pulseaudio must stay pulseaudio — start/heal rewrite the file.
     */
    public static function resolve(string $osFamily, string $existingConfig, string $helpOutput): string
    {
        if ($osFamily === 'Linux' || str_contains($existingConfig, 'backend = "pulseaudio"')) {
            return 'pulseaudio';
        }

        return str_contains($helpOutput, 'rodio') ? 'rodio' : 'portaudio';
    }

    /**
     * Never pin hw:*. On Linux the default PipeWire sink is the output.
     */
    public static function deviceLine(string $osFamily, ?string $audioDevice): ?string
    {
        if ($osFamily === 'Linux' || $audioDevice === null || $audioDevice === '') {
            return null;
        }

        if (str_starts_with($audioDevice, 'hw:')) {
            return null;
        }

        return 'device = "'.$audioDevice.'"';
    }

    public static function logShowsDeviceNotAvailable(string $logTail): bool
    {
        return str_contains($logTail, 'DeviceNotAvailable');
    }
}
