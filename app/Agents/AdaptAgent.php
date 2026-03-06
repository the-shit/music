<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class AdaptAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You analyze music listening behavior. Given the current session plan, playback events (skips, completions), and remaining phases, decide if adjustments are needed. Be concise.

        ## Output Format

        Respond with ONLY valid JSON, no markdown fencing, no explanation. Use this exact structure:

        {"should_adjust":false,"reasoning":"Brief explanation","adjusted_phases":[{"name":"Phase","energy":0.5,"valence":0.5,"tempo":120}]}

        Only populate adjusted_phases when should_adjust is true.
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return config('ai.session.adapt_model', 'anthropic/claude-haiku-4.5');
    }

    public function provider(): string
    {
        return 'openrouter';
    }
}
