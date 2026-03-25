<?php

namespace App\Mcp\Resources;

use App\Services\SpotifyPlayerService;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('devices')]
#[Description('Available Spotify playback devices')]
#[Uri('spotify://devices')]
#[MimeType('application/json')]
class DevicesResource extends Resource
{
    public function handle(SpotifyPlayerService $player): Response
    {
        $devices = $player->getDevices();

        $result = array_map(fn (array $device): array => [
            'id' => $device['id'],
            'name' => $device['name'],
            'type' => $device['type'],
            'active' => $device['is_active'] ?? false,
            'volume' => $device['volume_percent'] ?? null,
        ], $devices);

        return Response::text(json_encode($result, JSON_UNESCAPED_SLASHES));
    }
}
