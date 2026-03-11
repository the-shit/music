<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyService;
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

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($spotify): \Laravel\Mcp\Response {
            $spotify->pause();

            return Response::text('Playback paused.');
        });
    }
}
