<?php

namespace App\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class AdaptAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You analyze music listening behavior. Given the current session plan, playback events (skips, completions), and remaining phases, decide if adjustments are needed. Be concise.';
    }

    public function model(): string
    {
        return config('ai.session.adapt_model', 'anthropic/claude-haiku-4.5');
    }

    public function provider(): string
    {
        return 'openrouter';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'should_adjust' => $schema->boolean()
                ->description('Whether remaining phases should be adjusted')
                ->required(),
            'reasoning' => $schema->string()
                ->description('Brief explanation for the decision')
                ->required(),
            'adjusted_phases' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'energy' => $schema->number()->required(),
                    'valence' => $schema->number()->required(),
                    'tempo' => $schema->integer()->required(),
                ]))
                ->description('Replacement phases when adjustment is needed'),
        ];
    }
}
