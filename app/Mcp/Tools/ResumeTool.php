<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyService;
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

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($spotify): \Laravel\Mcp\Response {
            $spotify->resume();

            return Response::text('Playback resumed.');
        });
    }
}
