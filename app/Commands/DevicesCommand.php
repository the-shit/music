<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Commands\Concerns\ResolvesDevice;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class DevicesCommand extends Command
{
    use RequiresSpotifyConfig;
    use ResolvesDevice;

    protected $signature = 'devices
        {name? : Device name or ID to switch to}
        {--switch : Switch to a different device}
        {--device= : Device name or ID to switch to}
        {--json : Output as JSON}';

    protected $description = 'List or switch Spotify devices';

    public function handle(SpotifyPlayerService $player): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        try {
            $target = $this->switchTarget();
            $switching = (bool) $this->option('switch') || $target !== null;

            if ($switching) {
                return $this->switchPlayback($player, $target);
            }

            $devices = $player->getDevices();

            if ($devices === []) {
                if ($this->option('json')) {
                    $this->line(json_encode([]));

                    return self::SUCCESS;
                }

                warning('📱 No devices found');
                info('💡 Open Spotify on your phone, computer, or smart speaker');

                return self::SUCCESS;
            }

            if ($this->option('json')) {
                $this->line(json_encode($devices));

                return self::SUCCESS;
            }

            $this->displayDevices($devices);

            $activeDevice = null;
            $deviceTypes = [];
            foreach ($devices as $device) {
                if ($device['is_active'] ?? false) {
                    $activeDevice = $device['id'];
                }
                $deviceTypes[] = $device['type'];
            }

            $this->call('event:emit', [
                'event' => 'devices.listed',
                'data' => json_encode([
                    'device_count' => count($devices),
                    'active_device' => $activeDevice,
                    'available_types' => array_unique($deviceTypes),
                ]),
            ]);

            return self::SUCCESS;

        } catch (\Exception $e) {
            return $this->failWith($e->getMessage());
        }
    }

    private function switchTarget(): ?string
    {
        $argument = $this->argument('name');
        if (is_string($argument) && $argument !== '') {
            return $argument;
        }

        $option = $this->option('device');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        return null;
    }

    private function switchPlayback(SpotifyPlayerService $player, ?string $target): int
    {
        $json = (bool) $this->option('json');
        $canPrompt = ! $json && $this->input->isInteractive() && $target === null;

        if ($canPrompt) {
            return $this->promptAndTransfer($player);
        }

        $resolved = $this->resolveDevice($player, $target);

        if ($resolved === null) {
            $error = $target
                ? "Device '{$target}' not found"
                : 'No active device. Pass a name or ID, or start the local daemon.';

            return $this->failWith($error);
        }

        $player->transferPlayback($resolved['id']);

        if ($json) {
            $this->line(json_encode([
                'success' => true,
                'switched' => true,
                'device_id' => $resolved['id'],
                'device_name' => $resolved['name'],
            ]));
            $this->callSilently('event:emit', [
                'event' => 'device.switched',
                'data' => json_encode([
                    'device_id' => $resolved['id'],
                    'device_name' => $resolved['name'],
                ]),
            ]);
        } else {
            info('🔄 Switching to device...');
            info('✅ Playback transferred!');

            $this->call('event:emit', [
                'event' => 'device.switched',
                'data' => json_encode([
                    'device_id' => $resolved['id'],
                    'device_name' => $resolved['name'],
                ]),
            ]);
        }

        return self::SUCCESS;
    }

    private function promptAndTransfer(SpotifyPlayerService $player): int
    {
        $devices = $player->getDevices();

        if ($devices === []) {
            warning('📱 No devices found');
            info('💡 Open Spotify on your phone, computer, or smart speaker');

            return self::SUCCESS;
        }

        $choices = [];
        $activeDevice = null;

        foreach ($devices as $device) {
            $icon = match ($device['type']) {
                'Computer' => '💻',
                'Smartphone' => '📱',
                'Speaker' => '🔊',
                'TV' => '📺',
                'CastVideo' => '📺',
                'AVR' => '🎵',
                'AudioDongle' => '🎧',
                default => '🎵'
            };

            $status = $device['is_active'] ? '▶️' : '  ';
            $label = "{$status} {$icon} {$device['name']} ({$device['type']}) [{$device['volume_percent']}%]";
            $choices[$device['id']] = $label;

            if ($device['is_active']) {
                $activeDevice = $device['id'];
            }
        }

        $selected = select(
            label: '🎵 Select a device to switch to:',
            options: $choices,
            default: $activeDevice
        );

        if ($selected === $activeDevice) {
            info('✅ Already playing on this device');

            return self::SUCCESS;
        }

        info('🔄 Switching to device...');
        $player->transferPlayback($selected);

        info('✅ Playback transferred!');

        $this->call('event:emit', [
            'event' => 'device.switched',
            'data' => json_encode([
                'device_id' => $selected,
                'device_name' => $this->deviceNameFromChoices($devices, $selected),
            ]),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     */
    private function deviceNameFromChoices(array $devices, string $id): string
    {
        foreach ($devices as $device) {
            if (($device['id'] ?? null) === $id) {
                return (string) $device['name'];
            }
        }

        return $id;
    }

    private function failWith(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'success' => false,
                'error' => $message,
            ]));
        } else {
            error('❌ '.$message);
        }

        return self::FAILURE;
    }

    private function displayDevices(array $devices): void
    {
        info('📱 Available Spotify Devices:');
        $this->newLine();

        foreach ($devices as $device) {
            $icon = match ($device['type']) {
                'Computer' => '💻',
                'Smartphone' => '📱',
                'Speaker' => '🔊',
                'TV' => '📺',
                'CastVideo' => '📺',
                'AVR' => '🎵',
                'AudioDongle' => '🎧',
                default => '🎵'
            };

            $status = $device['is_active'] ? '▶️ ACTIVE' : '⏸️ Inactive';

            $this->line("  {$icon} <fg=cyan>{$device['name']}</>");
            $this->line("     Type: {$device['type']}");
            $this->line("     Volume: {$device['volume_percent']}%");
            $this->line("     Status: {$status}");
            $this->newLine();
        }

        if (! array_filter($devices, fn (array $d) => $d['is_active'])) {
            info("💡 No active device. Use 'spotify devices --switch' to activate one");
        }
    }
}
