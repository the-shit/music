<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\HandlesAuthErrors;
use App\Services\SpotifyPlayerService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('session_status')]
#[Description('Check the current music session status — what phase you are in, what is playing, and how much time is left.')]
class SessionStatusTool extends Tool
{
    use HandlesAuthErrors;

    public function handle(Request $request, SpotifyPlayerService $player): Response
    {
        return $this->withAuthHandling(function () use ($player): \Laravel\Mcp\Response {
            $state = $this->loadSessionState();

            if (! $state) {
                return Response::text('No active session. Use session_start to begin one.');
            }

            $elapsed = (int) ((time() - $state['started_at']) / 60);
            $totalDuration = $state['plan']['total_duration'] ?? 0;
            $remaining = max(0, $totalDuration - $elapsed);

            $playback = $player->getCurrentPlayback();
            $nowPlaying = $playback
                ? "{$playback['track']} by {$playback['artist']}"
                : 'Nothing playing';

            $output = 'Session: '.($state['curated']['playlist_name'] ?? $state['plan']['playlist_name'] ?? 'Unknown')."\n"
                ."Mode: {$state['mode']}\n"
                ."Now playing: {$nowPlaying}\n"
                ."Elapsed: {$elapsed} min | Remaining: ~{$remaining} min\n"
                ."Tracks queued: {$state['tracks_queued']}\n";

            if (isset($state['curated']['phases'])) {
                $currentPhase = $this->estimateCurrentPhase($state, $elapsed);
                $output .= "\nCurrent phase: {$currentPhase}";
            }

            return Response::text($output);
        });
    }

    private function estimateCurrentPhase(array $state, int $elapsedMinutes): string
    {
        $phases = $state['plan']['phases'] ?? [];
        $accumulated = 0;

        foreach ($phases as $phase) {
            $accumulated += $phase['duration_minutes'] ?? 0;
            if ($elapsedMinutes < $accumulated) {
                return "{$phase['name']} ({$phase['mood']}, ~{$phase['duration_minutes']} min)";
            }
        }

        return 'Session complete';
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

        // Consider sessions older than 4 hours as expired
        if (time() - $data['started_at'] > 4 * 3600) {
            unlink($path);

            return null;
        }

        return $data;
    }
}
