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

#[Name('repeat')]
#[Description('Set repeat mode: off (no repeat), track (repeat current song), or context (repeat album/playlist)')]
class RepeatTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()
                ->enum(['off', 'track', 'context'])
                ->description('Repeat mode')
                ->required(),
        ];
    }

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($request, $spotify): \Laravel\Mcp\Response {
            $mode = $request->get('mode');
            $spotify->setRepeat($mode);

            return Response::text("Repeat mode set to {$mode}.");
        });
    }
}
