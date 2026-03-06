<?php

namespace App\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class IntentParserAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        $presets = config('autopilot.mood_presets');

        $presetDescriptions = collect($presets)->map(function (array $preset, string $name): string {
            $features = collect($preset)
                ->map(fn (float|int $value, string $key): string => str_replace('target_', '', $key).': '.$value)
                ->implode(', ');

            return "  - {$name}: {$features}";
        })->implode("\n");

        return <<<INSTRUCTIONS
        You are a music session intent parser. Your job is to take natural language descriptions of a listening session and convert them into structured phases with audio feature targets.

        Each phase represents a distinct segment of the listening session with its own mood and audio characteristics.

        ## Audio Feature Reference

        These are the available mood presets and their audio feature targets. Use these as reference points when setting phase audio features:

        {$presetDescriptions}

        ## Audio Feature Ranges

        - energy: 0.0 (calm, ambient) to 1.0 (intense, energetic)
        - valence: 0.0 (sad, dark) to 1.0 (happy, cheerful)
        - tempo: BPM, typically 60-180

        ## Rules

        1. Parse the user's description into 2-6 distinct phases
        2. Each phase should have a clear name, mood, and audio feature targets
        3. The mood field should map to the closest preset name from the list above (chill, flow, hype, focus, party, upbeat, melancholy, ambient, workout, sleep)
        4. If the user specifies a duration, distribute it across phases proportionally. If no duration is specified, default to 60 minutes total
        5. Energy, valence, and tempo should reflect the described mood and create smooth transitions between phases
        6. Generate a creative but descriptive playlist name based on the session intent
        7. The total_duration should equal the sum of all phase durations

        ## Output Format

        Respond with ONLY valid JSON, no markdown fencing, no explanation. Use this exact structure:

        {"phases":[{"name":"Phase Name","mood":"preset_name","duration_minutes":15,"energy":0.5,"valence":0.5,"tempo":120,"description":"Brief vibe"}],"total_duration":60,"playlist_name":"Creative Name"}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return config('ai.session.parser_model');
    }

    public function provider(): string
    {
        return 'openrouter';
    }
}
