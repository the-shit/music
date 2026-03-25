<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyPlayerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('volume')]
#[Description('Get or set Spotify volume. Omit level to get current volume.')]
class VolumeTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'level' => $schema->integer()
                ->description('Volume level 0-100. Omit to get current volume.'),
        ];
    }

    public function handle(Request $request, SpotifyPlayerService $player): Response
    {
        return $this->withAuthHandling(function () use ($request, $player): \Laravel\Mcp\Response {
            $level = $request->get('level');

            if ($level === null) {
                $playback = $player->getCurrentPlayback();
                $volume = $playback['device']['volume_percent'] ?? null;

                if ($volume === null) {
                    return Response::text('Could not determine current volume.');
                }

                return Response::text("Current volume: {$volume}%");
            }

            $level = max(0, min(100, (int) $level));
            $player->setVolume($level);

            return Response::text("Volume set to {$level}%.");
        });
    }
}
