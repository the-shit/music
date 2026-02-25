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

#[Name('current')]
#[Description('Get the currently playing track, artist, album, progress, and playback state')]
#[IsReadOnly]
#[IsIdempotent]
class CurrentTool extends Tool
{
    public function handle(Request $request, SpotifyService $spotify): Response
    {
        $playback = $spotify->getCurrentPlayback();

        if (! $playback) {
            return Response::text('Nothing is currently playing.');
        }

        $progress = $this->formatTime($playback['progress_ms']);
        $duration = $this->formatTime($playback['duration_ms']);
        $state = $playback['is_playing'] ? 'Playing' : 'Paused';
        $device = $playback['device']['name'] ?? 'Unknown device';
        $shuffle = $playback['shuffle_state'] ? 'On' : 'Off';
        $repeat = $playback['repeat_state'];

        return Response::text(implode("\n", [
            "{$playback['name']} by {$playback['artist']}",
            "Album: {$playback['album']}",
            "Progress: {$progress} / {$duration}",
            "State: {$state} | Shuffle: {$shuffle} | Repeat: {$repeat}",
            "Device: {$device}",
        ]));
    }

    private function formatTime(int $ms): string
    {
        $seconds = intdiv($ms, 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
