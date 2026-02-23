<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class QueueShowCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'queue:show {--json : Output as JSON}';

    protected $description = 'Show upcoming tracks in the queue';

    public function handle()
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $spotify = app(SpotifyService::class);

        try {
            $queueData = $spotify->getQueue();
            $current = $queueData['currently_playing'] ?? null;
            $queue = $queueData['queue'] ?? [];

            if ($this->option('json')) {
                $this->line(json_encode([
                    'currently_playing' => $current ? [
                        'name' => $current['name'],
                        'artist' => $current['artists'][0]['name'] ?? 'Unknown',
                    ] : null,
                    'queue' => array_map(fn ($t) => [
                        'name' => $t['name'],
                        'artist' => $t['artists'][0]['name'] ?? 'Unknown',
                    ], array_slice($queue, 0, 20)),
                    'total' => count($queue),
                ]));

                return self::SUCCESS;
            }

            if ($current) {
                $artist = $current['artists'][0]['name'] ?? 'Unknown';
                $this->info("▶️  Now: {$current['name']} by {$artist}");
                $this->newLine();
            }

            if (empty($queue)) {
                $this->warn('Queue is empty');
                $this->info('Use: spotify queue "song name" to add tracks');

                return self::SUCCESS;
            }

            $this->info('📋 Up Next:');
            $this->newLine();

            foreach (array_slice($queue, 0, 20) as $i => $track) {
                $num = str_pad((string) ($i + 1), 2, ' ', STR_PAD_LEFT);
                $artist = $track['artists'][0]['name'] ?? 'Unknown';
                $this->line("  {$num}. {$track['name']} <fg=gray>by {$artist}</>");
            }

            if (count($queue) > 20) {
                $this->newLine();
                $this->info('  ... and '.(count($queue) - 20).' more');
            }

            $this->newLine();
            $this->info('📋 '.count($queue).' tracks queued');

        } catch (\Exception $e) {
            $this->error('Failed to get queue: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
