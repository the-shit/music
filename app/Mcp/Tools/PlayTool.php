<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('play')]
#[Description('Search for and play a song, artist, album, or playlist on Spotify')]
class PlayTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('What to play — song name, artist, album, or playlist')
                ->required(),
            'queue' => $schema->boolean()
                ->description('Add to queue instead of playing immediately')
                ->default(false),
        ];
    }

    public function handle(Request $request, SpotifyDiscoveryService $discovery, SpotifyPlayerService $player): Response
    {
        return $this->withAuthHandling(function () use ($request, $discovery, $player): \Laravel\Mcp\Response {
            $query = $request->get('query');
            $queue = $request->get('queue', false);

            $result = $discovery->search($query);

            if (! $result) {
                return Response::error("No results found for \"{$query}\".");
            }

            if ($queue) {
                $player->addToQueue($result['uri']);

                return Response::text("Queued: {$result['name']} by {$result['artist']}");
            }

            $player->play($result['uri']);

            return Response::text("Now playing: {$result['name']} by {$result['artist']}");
        });
    }
}
