<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\QdrantEventSink;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class HistoryBackfillCommand extends Command
{
    protected $signature = 'history:backfill {--limit= : Max events to backfill} {--dry-run : Show what would be backfilled without writing}';

    protected $description = 'Backfill historical events from events.jsonl into Qdrant';

    public function handle(QdrantEventSink $sink): int
    {
        if (! $sink->isConfigured()) {
            error('Qdrant is not configured. Set QDRANT_URL, QDRANT_COLLECTION, and QDRANT_EMBEDDING_SERVER in .env');

            return self::FAILURE;
        }

        $configDir = config('spotify.config_dir', ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/spotify-cli');
        $eventsFile = $configDir.'/events.jsonl';

        if (! file_exists($eventsFile)) {
            error("Events file not found: {$eventsFile}");

            return self::FAILURE;
        }

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $lines = file($eventsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $trackEvents = [];

        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if ($event === null) {
                continue;
            }

            $eventName = $event['event'] ?? '';
            if (! str_contains($eventName, 'track')) {
                continue;
            }

            $trackEvents[] = $event;
        }

        $total = count($trackEvents);
        if ($limit !== null) {
            $trackEvents = array_slice($trackEvents, 0, $limit);
        }

        $count = count($trackEvents);
        info("Found {$total} track events, backfilling {$count}");

        if ($dryRun) {
            foreach (array_slice($trackEvents, 0, 10) as $event) {
                $data = $event['data'] ?? [];
                $this->line("  {$data['artist']} — {$data['track']} ({$event['event']})");
            }
            if ($count > 10) {
                $this->line("  ... and ".($count - 10)." more");
            }
            warning('Dry run — no events written');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $success = 0;
        $skipped = 0;

        foreach ($trackEvents as $event) {
            $sink->sink($event);
            $success++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        info("Backfilled {$success} events into Qdrant ({$skipped} skipped)");

        return self::SUCCESS;
    }
}
