<?php

namespace App\Commands;

use App\Helpers\ConfigHelper;
use Illuminate\Support\Facades\Http;
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

        // Store events in config directory (PHAR-compatible, configurable for testing)
        $configDir = config('spotify.config_dir', ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/spotify-cli');
        $queueFile = config('app.events_file', $configDir.'/events.jsonl');

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

        // Forward to webhook if configured
        $this->forwardToWebhook($eventData);

        $this->info("✅ Event emitted: spotify.{$event}");

        return self::SUCCESS;
    }

    private function forwardToWebhook(array $eventData): void
    {
        if (! ConfigHelper::hasWebhook()) {
            return;
        }

        $config = ConfigHelper::getWebhookConfig();
        $payload = json_encode($eventData);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $config['secret']);

        try {
            Http::timeout(5)
                ->withHeaders([
                    'X-Spotify-CLI-Signature' => "sha256={$signature}",
                    'X-Spotify-CLI-Timestamp' => (string) $timestamp,
                    'X-Spotify-CLI-Event' => $eventData['event'],
                ])
                ->withBody($payload, 'application/json')
                ->post($config['url']);
        } catch (\Throwable $e) {
            // Fire-and-forget — log failures, don't block CLI
            $logFile = ConfigHelper::webhookErrorLogPath();
            $entry = json_encode([
                'error' => $e->getMessage(),
                'event' => $eventData['event'],
                'timestamp' => now()->toIso8601String(),
            ]);
            @file_put_contents($logFile, $entry."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
