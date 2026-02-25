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

#[Name('queue_show')]
#[Description('Show upcoming tracks in the Spotify queue')]
#[IsReadOnly]
#[IsIdempotent]
class QueueShowTool extends Tool
{
    public function handle(Request $request, SpotifyService $spotify): Response
    {
        $queue = $spotify->getQueue();

        if (empty($queue) || empty($queue['queue'])) {
            return Response::text('Queue is empty.');
        }

        $lines = [];

        if (! empty($queue['currently_playing'])) {
            $track = $queue['currently_playing'];
            $artist = $track['artists'][0]['name'] ?? 'Unknown';
            $lines[] = "Now playing: {$track['name']} by {$artist}";
            $lines[] = '';
        }

        $lines[] = 'Up next:';
        foreach (array_slice($queue['queue'], 0, 20) as $i => $track) {
            $artist = $track['artists'][0]['name'] ?? 'Unknown';
            $lines[] = ($i + 1).". {$track['name']} by {$artist}";
        }

        return Response::text(implode("\n", $lines));
    }
}
