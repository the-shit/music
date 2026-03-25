<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyPlayerService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('resume')]
#[Description('Resume Spotify playback from where it was paused')]
class ResumeTool extends Tool
{
    use HandlesAuthErrors;

    public function handle(Request $request, SpotifyPlayerService $player): Response
    {
        return $this->withAuthHandling(function () use ($player): \Laravel\Mcp\Response {
            $player->resume();

            return Response::text('Playback resumed.');
        });
    }
}
