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

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($request, $spotify): \Laravel\Mcp\Response {
            $query = $request->get('query');
            $queue = $request->get('queue', false);

            $result = $spotify->search($query);

            if (! $result) {
                return Response::error("No results found for \"{$query}\".");
            }

            if ($queue) {
                $spotify->addToQueue($result['uri']);

                return Response::text("Queued: {$result['name']} by {$result['artist']}");
            }

            $spotify->play($result['uri']);

            return Response::text("Now playing: {$result['name']} by {$result['artist']}");
        });
    }
}
