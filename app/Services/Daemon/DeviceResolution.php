<?php

declare(strict_types=1);

namespace App\Services\Daemon;

class DeviceResolution
{
    public function resolveDaemonName(?string $explicitName = null, ?Config $config = null): string
    {
        if ($explicitName) {
            return $explicitName;
        }

        $config = $config ?? new Config;
        if ($name = $config->deviceName()) {
            return $name;
        }

        return $this->resolveHostname();
    }

    private function resolveHostname(): string
    {
        $host = gethostname();
        if ($host === false || $host === '' || strcasecmp($host, 'localhost') === 0) {
            return 'spotify-cli';
        }

        $short = explode('.', $host, 2)[0];
        $short = trim($short, " \t\n\r\0\x0B\"'");

        return $short !== '' ? $short : 'spotify-cli';
    }
}
