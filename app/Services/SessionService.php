<?php

namespace App\Services;

use App\Agents\CuratorAgent;
use App\Agents\IntentParserAgent;

class SessionService
{
    private SpotifyService $spotify;

    private array $phases = [];

    private array $sessionPlan = [];

    public function __construct(SpotifyService $spotify)
    {
        $this->spotify = $spotify;
    }

    /**
     * Plan a session from natural language input using AI agents.
     */
    public function planFromDescription(string $description, ?int $durationMinutes = null): array
    {
        $prompt = $description;
        if ($durationMinutes) {
            $prompt .= " Duration: {$durationMinutes} minutes.";
        }

        $parser = new IntentParserAgent;
        $response = $parser->prompt($prompt);
        /** @var array{phases: array<array{name: string, mood: string, duration_minutes: int, energy: float, valence: float, tempo: int, description: string}>, total_duration: int, playlist_name: string} $plan */
        $plan = self::parseJson($response->text);

        $this->sessionPlan = $plan;
        $this->phases = $plan['phases'];

        return $plan;
    }

    /**
     * Plan a session from a mood preset (no AI needed).
     */
    public function planFromMood(string $mood, int $durationMinutes = 30): array
    {
        $presets = config('autopilot.mood_presets');
        $preset = $presets[$mood] ?? $presets['flow'];

        $energy = $preset['target_energy'] ?? 0.5;
        $valence = $preset['target_valence'] ?? 0.5;
        $tempo = $preset['target_tempo'] ?? 120;

        $this->phases = [
            [
                'name' => ucfirst($mood).' Session',
                'mood' => $mood,
                'duration_minutes' => $durationMinutes,
                'energy' => $energy,
                'valence' => $valence,
                'tempo' => $tempo,
                'description' => "A {$durationMinutes}-minute {$mood} session.",
            ],
        ];

        $this->sessionPlan = [
            'phases' => $this->phases,
            'total_duration' => $durationMinutes,
            'playlist_name' => ucfirst($mood).' Session',
        ];

        return $this->sessionPlan;
    }

    /**
     * Fetch candidate tracks for each phase using Spotify's recommendation engine.
     */
    public function fetchCandidates(): array
    {
        $candidates = [];

        foreach ($this->phases as $i => $phase) {
            $tracksPerMinute = 3; // ~3.5 min avg song, fetch extras for curation
            $limit = max(5, (int) ceil(($phase['duration_minutes'] ?? 10) * $tracksPerMinute / 3.5));
            $limit = min($limit, 30);

            $audioFeatures = $this->phaseToAudioFeatures($phase);
            $tracks = $this->spotify->getSmartRecommendations($limit, null, $audioFeatures);

            $candidates[$i] = [
                'phase' => $phase,
                'tracks' => $tracks,
            ];
        }

        return $candidates;
    }

    /**
     * Curate tracks using the CuratorAgent (AI).
     */
    public function curate(array $candidates): array
    {
        $prompt = "Session: {$this->sessionPlan['playlist_name']}\n\n";

        foreach ($candidates as $i => $group) {
            $phase = $group['phase'];
            $prompt .= "## Phase {$i}: {$phase['name']} ({$phase['duration_minutes']} min, {$phase['mood']})\n";
            $prompt .= "Energy: {$phase['energy']}, Valence: {$phase['valence']}, Tempo: {$phase['tempo']}\n";
            $prompt .= "Candidate tracks:\n";

            foreach ($group['tracks'] as $track) {
                $prompt .= "- {$track['uri']} — {$track['name']} by {$track['artist']}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "Select the best tracks for each phase. Maintain energy flow between phases.";

        $curator = new CuratorAgent;
        $response = $curator->prompt($prompt);

        /** @var array{playlist_name: string, playlist_description: string, phases: array<array{name: string, track_uris: string[], dj_note: string}>} */
        return self::parseJson($response->text);
    }

    /**
     * Queue all curated tracks to Spotify in phase order.
     */
    public function queueTracks(array $curatedPlan): int
    {
        $queued = 0;

        foreach ($curatedPlan['phases'] as $phase) {
            foreach ($phase['track_uris'] ?? [] as $uri) {
                try {
                    $this->spotify->addToQueue($uri);
                    $queued++;
                } catch (\Exception $e) {
                    // Skip tracks that fail to queue (e.g., unavailable in region)
                    continue;
                }
            }
        }

        return $queued;
    }

    /**
     * Simple flow: mood preset → fetch → queue. No AI agents needed.
     */
    public function quickSession(string $mood, int $durationMinutes = 30): array
    {
        $this->planFromMood($mood, $durationMinutes);
        $candidates = $this->fetchCandidates();

        // Skip curator — just use the recommendations directly
        $allTracks = [];
        $queued = 0;

        foreach ($candidates as $group) {
            foreach ($group['tracks'] as $track) {
                try {
                    $this->spotify->addToQueue($track['uri']);
                    $allTracks[] = $track;
                    $queued++;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return [
            'playlist_name' => $this->sessionPlan['playlist_name'],
            'mood' => $mood,
            'duration' => $durationMinutes,
            'tracks_queued' => $queued,
            'tracks' => $allTracks,
        ];
    }

    /**
     * Full AI-powered flow: parse → fetch → curate → queue.
     */
    public function aiSession(string $description, ?int $durationMinutes = null): array
    {
        $plan = $this->planFromDescription($description, $durationMinutes);
        $candidates = $this->fetchCandidates();
        $curated = $this->curate($candidates);
        $queued = $this->queueTracks($curated);

        return [
            'plan' => $plan,
            'curated' => $curated,
            'tracks_queued' => $queued,
        ];
    }

    public function getPhases(): array
    {
        return $this->phases;
    }

    public function getSessionPlan(): array
    {
        return $this->sessionPlan;
    }

    private static function parseJson(string $text): array
    {
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('AI response was not valid JSON: '.substr($text, 0, 200));
        }

        return $decoded;
    }

    private function phaseToAudioFeatures(array $phase): array
    {
        $features = [];

        if (isset($phase['energy'])) {
            $features['target_energy'] = $phase['energy'];
        }
        if (isset($phase['valence'])) {
            $features['target_valence'] = $phase['valence'];
        }
        if (isset($phase['tempo'])) {
            $features['target_tempo'] = $phase['tempo'];
        }

        // Merge in preset-specific features if mood maps to a preset
        $mood = $phase['mood'] ?? null;
        $presets = config('autopilot.mood_presets', []);

        if ($mood && isset($presets[$mood])) {
            foreach ($presets[$mood] as $key => $value) {
                if (! isset($features[$key])) {
                    $features[$key] = $value;
                }
            }
        }

        return $features;
    }
}
