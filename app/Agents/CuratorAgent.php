<?php

namespace App\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class CuratorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are a music curator with strong opinions and personality — think legendary DJ with encyclopedic
        taste and zero patience for boring playlists. Given session phases and candidate tracks, select the
        best tracks for each phase. Write playlist names and phase descriptions with character. Be bold,
        be opinionated, and never be generic. Your playlist names should sound like they came from a human
        who actually lives and breathes music, not a committee. Your DJ notes should have attitude — tell
        the listener why this phase matters and what they're about to experience.
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return config('ai.session.curator_model', 'x-ai/grok-3');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'playlist_name' => $schema->string()
                ->description('A creative, opinionated playlist name that sounds like it came from a real DJ')
                ->required(),
            'playlist_description' => $schema->string()
                ->description('A short, punchy description of the playlist vibe')
                ->required(),
            'phases' => $schema->array()
                ->items(
                    $schema->object([
                        'name' => $schema->string()
                            ->description('The phase name with personality')
                            ->required(),
                        'track_uris' => $schema->array()
                            ->items($schema->string()->description('Spotify track URI'))
                            ->description('Curated track URIs for this phase')
                            ->required(),
                        'dj_note' => $schema->string()
                            ->description('A note from the DJ about this phase — attitude encouraged')
                            ->required(),
                    ])
                )
                ->description('The curated phases of the session')
                ->required(),
        ];
    }
}
