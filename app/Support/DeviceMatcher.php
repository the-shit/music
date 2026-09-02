<?php

declare(strict_types=1);

namespace App\Support;

final class DeviceMatcher
{
    /**
     * Find a Connect device by name substring or exact ID.
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @return array{id: string, name: string}|null
     */
    public static function find(array $devices, string $search): ?array
    {
        foreach ($devices as $device) {
            $id = (string) ($device['id'] ?? '');
            $name = (string) ($device['name'] ?? '');

            if ($id === $search || ($name !== '' && stripos($name, $search) !== false)) {
                return ['id' => $id, 'name' => $name];
            }
        }

        return null;
    }
}
