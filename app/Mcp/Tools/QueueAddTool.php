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

#[Name('queue_add')]
#[Description('Search for a track and add it to the Spotify queue')]
class QueueAddTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Song to search for and add to queue')
                ->required(),
        ];
    }

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($request, $spotify): \Laravel\Mcp\Response {
            $query = $request->get('query');
            $result = $spotify->search($query);

            if (! $result) {
                return Response::error("No results found for \"{$query}\".");
            }

            $spotify->addToQueue($result['uri']);

            return Response::text("Added to queue: {$result['name']} by {$result['artist']}");
        });
    }
}
