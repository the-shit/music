<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('shuffle')]
#[Description('Toggle or set shuffle mode. Omit enabled to toggle.')]
class ShuffleTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'enabled' => $schema->boolean()
                ->description('Set shuffle on or off. Omit to toggle.'),
        ];
    }

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($request, $spotify): \Laravel\Mcp\Response {
            $enabled = $request->get('enabled');

            if ($enabled === null) {
                $playback = $spotify->getCurrentPlayback();
                $current = $playback['shuffle_state'] ?? false;
                $enabled = ! $current;
            }

            $spotify->setShuffle((bool) $enabled);
            $state = $enabled ? 'on' : 'off';

            return Response::text("Shuffle turned {$state}.");
        });
    }
}
