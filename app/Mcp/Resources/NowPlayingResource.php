<?php

namespace App\Mcp\Resources;

use App\Services\SpotifyPlayerService;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('now-playing')]
#[Description('Current Spotify playback state — track, artist, album, progress, device')]
#[Uri('spotify://now-playing')]
#[MimeType('application/json')]
class NowPlayingResource extends Resource
{
    public function handle(SpotifyPlayerService $player): Response
    {
        $playback = $player->getCurrentPlayback();

        if (! $playback) {
            return Response::text(json_encode(['playing' => false]));
        }

        return Response::text(json_encode([
            'playing' => $playback['is_playing'],
            'track' => $playback['name'],
            'artist' => $playback['artist'],
            'album' => $playback['album'],
            'progress_ms' => $playback['progress_ms'],
            'duration_ms' => $playback['duration_ms'],
            'shuffle' => $playback['shuffle_state'],
            'repeat' => $playback['repeat_state'],
            'device' => $playback['device']['name'] ?? null,
        ], JSON_UNESCAPED_SLASHES));
    }
}
