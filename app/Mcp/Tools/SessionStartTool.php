<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SessionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('session_start')]
#[Description('Start a music session. Describe the vibe you want (e.g. "chill focus for coding", "start mellow then build to high energy") and AI agents will plan phases, pick tracks, and queue everything to Spotify.')]
class SessionStartTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Describe the session you want — mood, vibe, activity, energy arc')
                ->required(),
            'duration' => $schema->integer()
                ->description('Session length in minutes (default: 60)')
                ->default(60),
        ];
    }

    public function handle(Request $request, SessionService $session): Response
    {
        return $this->withAuthHandling(function () use ($request, $session) {
            $prompt = $request->get('prompt');
            $duration = $request->get('duration', 60);

            $hasOpenRouter = ! empty(env('OPENROUTER_API_KEY'));

            if ($hasOpenRouter) {
                $result = $session->aiSession($prompt, $duration);
                $plan = $result['plan'];
                $curated = $result['curated'];
                $queued = $result['tracks_queued'];

                self::saveSessionState([
                    'plan' => $plan,
                    'curated' => $curated,
                    'tracks_queued' => $queued,
                    'started_at' => time(),
                    'mode' => 'ai',
                ]);

                $phases = collect($curated['phases'] ?? [])
                    ->map(fn (array $phase) => "- {$phase['name']}: {$phase['dj_note']} (" . count($phase['track_uris'] ?? []) . ' tracks)')
                    ->implode("\n");

                return Response::text(
                    "{$curated['playlist_name']}\n{$curated['playlist_description']}\n\n"
                    . "Phases:\n{$phases}\n\n"
                    . "{$queued} tracks queued. Enjoy!"
                );
            }

            // Fallback: extract mood keyword from prompt
            $mood = self::extractMood($prompt);
            $result = $session->quickSession($mood, $duration);

            self::saveSessionState([
                'plan' => $session->getSessionPlan(),
                'tracks_queued' => $result['tracks_queued'],
                'started_at' => time(),
                'mode' => 'quick',
                'mood' => $mood,
            ]);

            return Response::text(
                "{$result['playlist_name']}\n\n"
                . "{$result['tracks_queued']} tracks queued for a {$duration}-minute {$mood} session."
            );
        });
    }

    private static function extractMood(string $prompt): string
    {
        $moods = ['chill', 'flow', 'hype', 'focus', 'party', 'upbeat', 'melancholy', 'ambient', 'workout', 'sleep'];
        $lower = strtolower($prompt);

        foreach ($moods as $mood) {
            if (str_contains($lower, $mood)) {
                return $mood;
            }
        }

        $keywords = [
            'relax' => 'chill', 'calm' => 'ambient', 'energy' => 'hype', 'pump' => 'workout',
            'sad' => 'melancholy', 'happy' => 'upbeat', 'concentrate' => 'focus', 'code' => 'flow',
            'work' => 'flow', 'study' => 'focus', 'dance' => 'party', 'intense' => 'hype',
        ];

        foreach ($keywords as $keyword => $mood) {
            if (str_contains($lower, $keyword)) {
                return $mood;
            }
        }

        return 'flow';
    }

    private static function saveSessionState(array $state): void
    {
        $path = self::sessionStatePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
    }

    public static function sessionStatePath(): string
    {
        return ($_ENV['HOME'] ?? $_SERVER['HOME'] ?? '/tmp') . '/.spotify-cli/session.json';
    }
}
