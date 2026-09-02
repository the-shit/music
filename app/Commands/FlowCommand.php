<?php

namespace App\Commands;

use App\Commands\Concerns\QueuesMoodTracks;
use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use App\Support\MoodSearch;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class FlowCommand extends Command
{
    use QueuesMoodTracks;
    use RequiresSpotifyConfig;

    protected $signature = 'flow
        {--duration=60 : Approximate session duration in minutes}
        {--limit= : Number of tracks to queue (overrides --duration)}
        {--device= : Device name or ID to play on}
        {--json : Output as JSON}';

    protected $description = 'Queue focus/flow state music for deep work';

    private SpotifyPlayerService $player;

    private SpotifyDiscoveryService $discovery;

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery): int
    {
        $this->player = $player;
        $this->discovery = $discovery;

        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }
        $duration = (int) $this->option('duration');
        $limit = $this->option('limit');

        // Use explicit limit if provided, otherwise calculate from duration (~3.5 min avg per track)
        $trackCount = $limit !== null ? (int) $limit : max(5, (int) ceil($duration / 3.5));

        try {
            $deviceId = $this->resolvePlaybackDeviceId($player);
            if ($deviceId === false) {
                return self::FAILURE;
            }

            $queries = [
                'genre:ambient instrumental',
                'genre:study lo-fi beats',
                'instrumental focus ambient',
            ];

            $allTracks = MoodSearch::gather($this->discovery, $queries, $trackCount, 'flow');

            if ($allTracks === []) {
                warning('No flow tracks found.');

                return self::FAILURE;
            }

            $queued = $this->queueTracks($allTracks, $deviceId);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'mood' => 'flow',
                    'duration_minutes' => $duration,
                    'queued' => $queued,
                    'device_id' => $deviceId,
                ]));

                return self::SUCCESS;
            }

            info("🧘 Flow mode activated — {$duration} min session");
            $this->newLine();
            foreach ($queued as $i => $track) {
                $action = $i === 0 ? '▶️ Now' : '📋 Queue';
                $this->line("  {$action}: <fg=cyan>{$track['name']}</> by {$track['artist']}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->reportFailure($e);

            return self::FAILURE;
        }
    }
}
