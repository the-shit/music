<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SessionService;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class SessionCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'session
        {description? : Natural language session description (e.g. "chill for 20 mins then hype me up")}
        {--mood= : Use a mood preset directly (chill, flow, hype, focus, party, upbeat, melancholy, ambient, workout, sleep)}
        {--duration=30 : Session duration in minutes}
        {--ai : Use AI agents for smart planning and curation (requires OpenRouter API key)}';

    protected $description = 'Start an AI-powered listening session with mood-aware phases';

    private SpotifyService $spotify;

    public function handle(SpotifyService $spotify): int
    {
        $this->spotify = $spotify;

        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }
        $session = new SessionService($spotify);

        $description = $this->argument('description');
        $mood = $this->option('mood');
        $duration = (int) $this->option('duration');
        $useAi = $this->option('ai') || $description !== null;

        // Validate we have something to work with
        if (! $description && ! $mood) {
            error('Provide a description or --mood flag.');
            $this->line('');
            $this->line('  <fg=cyan>spotify session "chill for 20 mins then hype me up"</>');
            $this->line('  <fg=cyan>spotify session --mood=flow --duration=45</>');
            $this->line('  <fg=cyan>spotify session "deep work focus" --ai</>');

            return self::FAILURE;
        }

        // Validate mood preset
        $validMoods = array_keys(config('autopilot.mood_presets', []));
        if ($mood && ! in_array($mood, $validMoods)) {
            error("Unknown mood: {$mood}");
            $this->line('Available: '.implode(', ', $validMoods));

            return self::FAILURE;
        }

        // Check for active device
        $device = $spotify->getActiveDevice();
        if (! $device) {
            error('No active Spotify device found. Start playing something first.');

            return self::FAILURE;
        }

        if ($useAi && $description) {
            return $this->runAiSession($session, $description, $duration);
        }

        return $this->runQuickSession($session, $mood ?? 'flow', $duration);
    }

    private function runQuickSession(SessionService $session, string $mood, int $duration): int
    {
        info("Starting {$mood} session ({$duration} min)...");
        $this->newLine();

        try {
            $result = $session->quickSession($mood, $duration);
        } catch (\Exception $e) {
            error("Session failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result['tracks_queued'] === 0) {
            warning('No tracks could be queued. Try a different mood or check your Spotify connection.');

            return self::FAILURE;
        }

        info("Queued {$result['tracks_queued']} tracks for your {$mood} session.");
        $this->newLine();

        $this->table(
            ['#', 'Track', 'Artist'],
            collect($result['tracks'])->map(fn (array $track, int $i): array => [
                $i + 1,
                $track['name'] ?? 'Unknown',
                $track['artist'] ?? 'Unknown',
            ])->take(20)->all()
        );

        if (count($result['tracks']) > 20) {
            $remaining = count($result['tracks']) - 20;
            $this->line("  ...and {$remaining} more");
        }

        return self::SUCCESS;
    }

    private function runAiSession(SessionService $session, string $description, int $duration): int
    {
        // Check for OpenRouter API key
        if (! config('ai.providers.openrouter.key')) {
            warning('No OpenRouter API key configured. Falling back to quick session.');
            $this->line('Set OPENROUTER_API_KEY in your environment for AI-powered sessions.');
            $this->newLine();

            // Try to extract a mood from the description
            $mood = $this->extractMoodFallback($description);

            return $this->runQuickSession($session, $mood, $duration);
        }

        info('Planning session...');

        try {
            $plan = $session->planFromDescription($description, $duration);
        } catch (\Exception $e) {
            warning("AI planning failed: {$e->getMessage()}");
            $this->line('Falling back to quick session.');
            $mood = $this->extractMoodFallback($description);

            return $this->runQuickSession($session, $mood, $duration);
        }

        info("Session: {$plan['playlist_name']} ({$plan['total_duration']} min)");
        $this->newLine();

        // Show phases
        $this->table(
            ['Phase', 'Mood', 'Duration', 'Energy', 'Vibe'],
            collect($plan['phases'])->map(fn (array $phase): array => [
                $phase['name'],
                $phase['mood'],
                $phase['duration_minutes'].'m',
                $this->energyBar($phase['energy'] ?? 0.5),
                $phase['description'] ?? '',
            ])->all()
        );

        $this->newLine();
        info('Finding tracks...');

        try {
            $candidates = $session->fetchCandidates();
        } catch (\Exception $e) {
            error("Failed to fetch tracks: {$e->getMessage()}");

            return self::FAILURE;
        }

        $totalCandidates = collect($candidates)->sum(fn (array $g): int => count($g['tracks']));
        $this->line("Found {$totalCandidates} candidates across ".count($candidates).' phases.');

        info('Curating...');

        try {
            $curated = $session->curate($candidates);
        } catch (\Exception $e) {
            warning("Curation failed: {$e->getMessage()}");
            $this->line('Queueing uncurated tracks instead.');

            // Fallback: queue all candidates directly
            $queued = 0;
            foreach ($candidates as $group) {
                foreach ($group['tracks'] as $track) {
                    try {
                        $this->spotify->addToQueue($track['uri']);
                        $queued++;
                    } catch (\Exception) {
                        continue;
                    }
                }
            }

            info("Queued {$queued} tracks.");

            return $queued > 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        info("Curated: {$curated['playlist_name']}");
        if (! empty($curated['playlist_description'])) {
            $this->line("  {$curated['playlist_description']}");
        }
        $this->newLine();

        // Show curated phases with DJ notes
        foreach ($curated['phases'] as $phase) {
            $trackCount = count($phase['track_uris'] ?? []);
            $this->line("<fg=cyan>{$phase['name']}</> ({$trackCount} tracks)");
            if (! empty($phase['dj_note'])) {
                $this->line("  <fg=gray>{$phase['dj_note']}</>");
            }
        }

        $this->newLine();
        info('Queueing...');

        $queued = $session->queueTracks($curated);

        if ($queued === 0) {
            error('No tracks could be queued.');

            return self::FAILURE;
        }

        info("Queued {$queued} tracks. Enjoy your session.");

        return self::SUCCESS;
    }

    private function energyBar(float $energy): string
    {
        $filled = (int) round($energy * 5);
        $empty = 5 - $filled;

        return str_repeat('█', $filled).str_repeat('░', $empty).' '.round($energy * 100).'%';
    }

    private function extractMoodFallback(string $description): string
    {
        $description = strtolower($description);
        $moods = [
            'chill' => ['chill', 'relax', 'calm', 'mellow', 'easy'],
            'flow' => ['flow', 'work', 'deep', 'productive', 'concentrate'],
            'focus' => ['focus', 'study', 'think', 'concentrate'],
            'hype' => ['hype', 'pump', 'intense', 'hard', 'rage'],
            'party' => ['party', 'dance', 'club', 'celebrate'],
            'workout' => ['workout', 'gym', 'run', 'exercise', 'lift'],
            'sleep' => ['sleep', 'bedtime', 'drift', 'rest'],
            'ambient' => ['ambient', 'background', 'atmospheric'],
            'melancholy' => ['sad', 'melancholy', 'moody', 'dark'],
            'upbeat' => ['upbeat', 'happy', 'cheerful', 'bright'],
        ];

        foreach ($moods as $mood => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($description, $keyword)) {
                    return $mood;
                }
            }
        }

        return 'flow';
    }
}
