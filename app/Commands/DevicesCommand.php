<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class DevicesCommand extends Command
{
    use ChecksAuthorization;
    protected $signature = 'devices {--switch : Switch to a different device}';

    protected $description = 'List or switch Spotify devices';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            error('❌ Spotify not configured');
            info('💡 Run "spotify setup" first');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:devices')) {
            return self::FAILURE;
        }

        try {
            $devices = $spotify->getDevices();

            if (empty($devices)) {
                warning('📱 No devices found');
                info('💡 Open Spotify on your phone, computer, or smart speaker');

                return self::SUCCESS;
            }

            if (! $this->option('switch')) {
                // Just list devices
                $this->displayDevices($devices);

                // Emit devices listed event
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
            }

            // Interactive device switching
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

            // Transfer playback
            info('🔄 Switching to device...');
            $spotify->transferPlayback($selected);

            info('✅ Playback transferred!');

            // Emit event
            $this->call('event:emit', [
                'event' => 'device.switched',
                'data' => json_encode([
                    'device_id' => $selected,
                    'device_name' => array_search($selected, array_keys($choices)),
                ]),
            ]);

            return self::SUCCESS;

        } catch (\Exception $e) {
            error('❌ '.$e->getMessage());

            return self::FAILURE;
        }
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

        if (! array_filter($devices, fn ($d) => $d['is_active'])) {
            info("💡 No active device. Use 'spotify devices --switch' to activate one");
        }
    }
}
