<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyDiscoveryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search')]
#[Description('Search the Spotify catalog for tracks, artists, albums, or playlists')]
#[IsReadOnly]
#[IsIdempotent]
class SearchTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query')
                ->required(),
            'type' => $schema->string()
                ->enum(['track', 'artist', 'album', 'playlist'])
                ->description('Type of result to search for')
                ->default('track'),
            'limit' => $schema->integer()
                ->description('Number of results (1-20)')
                ->default(5),
        ];
    }

    public function handle(Request $request, SpotifyDiscoveryService $discovery): Response
    {
        return $this->withAuthHandling(function () use ($request, $discovery): \Laravel\Mcp\Response {
            $query = $request->get('query');
            $type = $request->get('type', 'track');
            $limit = min(20, max(1, (int) $request->get('limit', 5)));

            $results = $discovery->searchMultiple($query, $type, $limit);

            if ($results === []) {
                return Response::text("No {$type}s found for \"{$query}\".");
            }

            $lines = ["Search results for \"{$query}\" ({$type}s):"];
            foreach ($results as $i => $result) {
                $artist = $result['artist'] ?? '';
                $album = $result['album'] ?? '';
                $line = ($i + 1).". {$result['name']}";
                if ($artist) {
                    $line .= " by {$artist}";
                }
                if ($album) {
                    $line .= " ({$album})";
                }
                $lines[] = $line;
            }

            return Response::text(implode("\n", $lines));
        });
    }
}
