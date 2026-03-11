<?php

namespace App\Mcp\Tools;

use App\Agents\AdaptAgent;
use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('session_adjust')]
#[Description('Adjust the current music session. Give feedback like "more energy", "too intense", "add some jazz", "shift to something darker" and the AI will adapt the remaining session.')]
class SessionAdjustTool extends Tool
{
    use HandlesAuthErrors;

    public function schema(JsonSchema $schema): array
    {
        return [
            'feedback' => $schema->string()
                ->description('What to change — e.g. "more energy", "too intense", "something darker"')
                ->required(),
        ];
    }

    public function handle(Request $request, SpotifyService $spotify): Response
    {
        return $this->withAuthHandling(function () use ($request, $spotify): \Laravel\Mcp\Response {
            $state = $this->loadSessionState();

            if (! $state) {
                return Response::error('No active session. Use session_start to begin one.');
            }

            $feedback = $request->get('feedback');
            $elapsed = (int) ((time() - $state['started_at']) / 60);
            $remaining = max(0, ($state['plan']['total_duration'] ?? 60) - $elapsed);

            $playback = $spotify->getCurrentPlayback();
            $nowPlaying = $playback
                ? "{$playback['track']} by {$playback['artist']}"
                : 'Unknown';

            $prompt = 'Current session: '.($state['plan']['playlist_name'] ?? 'Unknown')."\n"
                ."Now playing: {$nowPlaying}\n"
                ."Elapsed: {$elapsed} min, Remaining: ~{$remaining} min\n"
                .'Original phases: '.json_encode($state['plan']['phases'] ?? [])."\n"
                ."User feedback: {$feedback}\n\n"
                ."Should we adjust? If yes, provide new phase targets for the remaining {$remaining} minutes.";

            $agent = new AdaptAgent;
            $response = $agent->prompt($prompt);

            $text = $response->text;
            $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
            $text = preg_replace('/\s*```\s*$/m', '', $text);
            $decoded = json_decode(trim($text), true);

            if (! is_array($decoded)) {
                return Response::error('AI adjustment failed. Try rephrasing your feedback.');
            }

            if (empty($decoded['should_adjust'])) {
                return Response::text("No adjustment needed: {$decoded['reasoning']}");
            }

            // Queue new tracks based on adjusted phases
            $queued = 0;
            foreach ($decoded['adjusted_phases'] ?? [] as $phase) {
                $audioFeatures = [];
                if (isset($phase['energy'])) {
                    $audioFeatures['target_energy'] = $phase['energy'];
                }
                if (isset($phase['valence'])) {
                    $audioFeatures['target_valence'] = $phase['valence'];
                }
                if (isset($phase['tempo'])) {
                    $audioFeatures['target_tempo'] = $phase['tempo'];
                }

                $tracks = $spotify->getSmartRecommendations(5, null, $audioFeatures);
                foreach ($tracks as $track) {
                    try {
                        $spotify->addToQueue($track['uri']);
                        $queued++;
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }

            return Response::text(
                "Adjusted! {$decoded['reasoning']}\n"
                ."{$queued} new tracks queued based on your feedback."
            );
        });
    }

    private function loadSessionState(): ?array
    {
        $path = SessionStartTool::sessionStatePath();

        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['started_at'])) {
            return null;
        }

        if (time() - $data['started_at'] > 4 * 3600) {
            unlink($path);

            return null;
        }

        return $data;
    }
}
