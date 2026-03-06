<?php

namespace App\Mcp;

use App\Mcp\Resources\DevicesResource;
use App\Mcp\Resources\NowPlayingResource;
use App\Mcp\Tools\CurrentTool;
use App\Mcp\Tools\DevicesTool;
use App\Mcp\Tools\PauseTool;
use App\Mcp\Tools\PlayTool;
use App\Mcp\Tools\QueueAddTool;
use App\Mcp\Tools\QueueShowTool;
use App\Mcp\Tools\RepeatTool;
use App\Mcp\Tools\ResumeTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\SessionAdjustTool;
use App\Mcp\Tools\SessionStartTool;
use App\Mcp\Tools\SessionStatusTool;
use App\Mcp\Tools\ShuffleTool;
use App\Mcp\Tools\SkipTool;
use App\Mcp\Tools\VolumeTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('spotify')]
#[Version('1.0.0')]
#[Instructions('Controls Spotify playback from the terminal. Play music, manage the queue, search the catalog, control volume and playback modes. Requires "spotify setup" and "spotify login" to be run first, and an active Spotify device (desktop app, phone, browser, or spotifyd daemon).')]
class SpotifyServer extends Server
{
    protected array $tools = [
        PlayTool::class,
        PauseTool::class,
        ResumeTool::class,
        SkipTool::class,
        CurrentTool::class,
        VolumeTool::class,
        QueueAddTool::class,
        QueueShowTool::class,
        SearchTool::class,
        DevicesTool::class,
        ShuffleTool::class,
        RepeatTool::class,
        SessionStartTool::class,
        SessionStatusTool::class,
        SessionAdjustTool::class,
    ];

    protected array $resources = [
        NowPlayingResource::class,
        DevicesResource::class,
    ];
}
