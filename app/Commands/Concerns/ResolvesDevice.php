<?php

namespace App\Commands\Concerns;

use App\Services\SpotifyService;

use function Laravel\Prompts\info;

trait ResolvesDevice
{
    /**
     * Resolve a device ID from a name, or fall back to the local daemon device.
     *
     * @return array{id: string, name: string}|null
     */
    protected function resolveDevice(SpotifyService $spotify, ?string $deviceName = null): ?array
    {
        // Explicit device name takes priority
        if ($deviceName) {
            $devices = $spotify->getDevices();
            $match = $this->findDevice($devices, $deviceName);

            if ($match) {
                info("🔊 Using device: {$match['name']}");
            }

            return $match;
        }

        // Fall back to daemon device if available
        $daemonName = $this->readDaemonDeviceName();

        if (! $daemonName) {
            return null;
        }

        $devices = $spotify->getDevices();
        $match = $this->findDevice($devices, $daemonName);

        if ($match) {
            info("Using daemon device: {$match['name']}");
        }

        return $match;
    }

    /**
     * Find a device by name or ID in the device list.
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @return array{id: string, name: string}|null
     */
    private function findDevice(array $devices, string $search): ?array
    {
        foreach ($devices as $device) {
            if (stripos($device['name'], $search) !== false || $device['id'] === $search) {
                return ['id' => $device['id'], 'name' => $device['name']];
            }
        }

        return null;
    }

    /**
     * Read the daemon device name from the spotifyd config file.
     */
    private function readDaemonDeviceName(): ?string
    {
        $configFile = $this->daemonConfigPath();

        if (! file_exists($configFile)) {
            return null;
        }

        $contents = file_get_contents($configFile);

        if ($contents && preg_match('/device_name\s*=\s*"([^"]+)"/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function daemonConfigPath(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');

        return $home.'/.config/spotify-cli/spotifyd.conf';
    }
}
