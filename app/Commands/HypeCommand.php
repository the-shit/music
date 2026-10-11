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

class HypeCommand extends Command
{
    use QueuesMoodTracks;
    use RequiresSpotifyConfig;

    protected $signature = 'hype
        {--limit=10 : Number of tracks to queue}
        {--device= : Device name or ID to play on}
        {--json : Output as JSON}';

    protected $description = 'Queue high-energy hype tracks';

    private SpotifyPlayerService $player;

    private SpotifyDiscoveryService $discovery;

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery): int
    {
        $this->player = $player;
        $this->discovery = $discovery;

        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        try {
            $deviceId = $this->resolvePlaybackDeviceId($player);
            if ($deviceId === false) {
                return self::FAILURE;
            }

            $queries = [
                'high energy workout pump up',
                'hype rap energy anthem',
                'electronic dance energy bass',
            ];

            $tracks = MoodSearch::gather($this->discovery, $queries, (int) $this->option('limit'), 'hype');

            if ($tracks === []) {
                warning('No hype tracks found.');

                return self::FAILURE;
            }

            $queued = $this->queueTracks($tracks, $deviceId);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'mood' => 'hype',
                    'queued' => $queued,
                    'device_id' => $deviceId,
                ]));

                return self::SUCCESS;
            }

            info('🔥 Hype mode activated!');
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
