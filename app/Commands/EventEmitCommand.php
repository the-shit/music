<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class EventEmitCommand extends Command
{
    protected $signature = 'event:emit {event : Event name} {data? : JSON data}';

    protected $description = 'Emit an event to the event bus';

    public function handle()
    {
        $event = $this->argument('event');
        $data = $this->argument('data') ? json_decode($this->argument('data'), true) : [];

        // Simple file-based event queue
        $eventData = [
            'component' => 'spotify',
            'event' => "spotify.{$event}",
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ];

        // Store events in storage directory
        $queueFile = base_path('storage/events.jsonl');

        // Ensure directory exists
        $dir = dirname($queueFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Append event to queue
        file_put_contents(
            $queueFile,
            json_encode($eventData)."\n",
            FILE_APPEND | LOCK_EX
        );

        $this->info("✅ Event emitted: spotify.{$event}");

        return self::SUCCESS;
    }
}
