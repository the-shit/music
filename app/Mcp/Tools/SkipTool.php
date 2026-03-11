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

#[Name('skip')]
#[Description('Skip to the next or previous track')]
class SkipTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'direction' => $schema->string()
                ->enum(['next', 'previous'])
                ->description('Skip direction')
                ->default('next'),
        ];
    }

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($request, $spotify): \Laravel\Mcp\Response {
            $direction = $request->get('direction', 'next');

            if ($direction === 'previous') {
                $spotify->previous();

                return Response::text('Skipped to previous track.');
            }

            $spotify->next();

            return Response::text('Skipped to next track.');
        });
    }
}
