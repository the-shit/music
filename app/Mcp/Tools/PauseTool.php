<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyPlayerService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('pause')]
#[Description('Pause Spotify playback')]
class PauseTool extends Tool
{
    use HandlesAuthErrors;

    public function handle(Request $request, SpotifyPlayerService $player): Response
    {
        return $this->withAuthHandling(function () use ($player): \Laravel\Mcp\Response {
            $player->pause();

            return Response::text('Playback paused.');
        });
    }
}
