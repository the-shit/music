<?php

namespace App\Mcp\Tools;

use App\Services\SpotifyService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('devices')]
#[Description('List available Spotify playback devices')]
#[IsReadOnly]
#[IsIdempotent]
class DevicesTool extends Tool
{
    public function handle(Request $request, SpotifyService $spotify): Response
    {
        $devices = $spotify->getDevices();

        if (empty($devices)) {
            return Response::text('No Spotify devices available. Open Spotify on any device.');
        }

        $lines = ['Available devices:'];
        foreach ($devices as $device) {
            $active = ($device['is_active'] ?? false) ? ' (active)' : '';
            $volume = $device['volume_percent'] ?? '?';
            $lines[] = "- {$device['name']} [{$device['type']}] vol:{$volume}%{$active}";
        }

        return Response::text(implode("\n", $lines));
    }
}
