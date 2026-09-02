<?php

namespace App\Commands\Concerns;

use App\Services\Daemon\Process;
use App\Services\SpotifyPlayerService;
use App\Support\DeviceMatcher;

use function Laravel\Prompts\info;

trait ResolvesDevice
{
    /**
     * Resolve a device ID from a name, or fall back to the local daemon device.
     *
     * @return array{id: string, name: string}|null
     */
    protected function resolveDevice(SpotifyPlayerService $player, ?string $deviceName = null): ?array
    {
        // Explicit device name takes priority
        if ($deviceName) {
            $devices = $player->getDevices();
            $match = $this->findDevice($devices, $deviceName);

            if ($match && ! $this->option('json')) {
                info("🔊 Using device: {$match['name']}");
            }

            return $match;
        }

        // Fall back to daemon device if available
        $daemonName = $this->readDaemonDeviceName();

        if (! $daemonName) {
            return null;
        }

        $devices = $player->getDevices();
        $match = $this->findDevice($devices, $daemonName);
        $alive = app(Process::class)->isAlive();

        // Connect often still lists the speaker after spotifyd is killed.
        // Missing from Connect OR process dead → one heal, then retry lookup.
        if (! $match || ! $alive) {
            $this->healLocalDaemon();
            $devices = $player->getDevices();
            $match = $this->findDevice($devices, $daemonName);
        }

        if ($match && ! $this->option('json')) {
            info("Using daemon device: {$match['name']}");
        }

        return $match;
    }

    /**
     * Restart the local Connect speaker once, then let the caller retry lookup.
     */
    private function healLocalDaemon(): void
    {
        $arguments = [
            'action' => 'health',
            '--heal' => true,
        ];

        if ($this->option('json')) {
            $this->callSilently('daemon', $arguments);

            return;
        }

        $this->call('daemon', $arguments);
    }

    /**
     * Find a device by name or ID in the device list.
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @return array{id: string, name: string}|null
     */
    private function findDevice(array $devices, string $search): ?array
    {
        return DeviceMatcher::find($devices, $search);
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
