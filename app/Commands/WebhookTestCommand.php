<?php

namespace App\Commands;

use App\Helpers\ConfigHelper;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class WebhookTestCommand extends Command
{
    protected $signature = 'webhook:test';

    protected $description = 'Send a test ping to the configured webhook';

    public function handle(): int
    {
        $config = ConfigHelper::getWebhookConfig();

        if (empty($config['url']) || empty($config['secret'])) {
            warning('No webhook configured');
            info('Run: spotify webhook:configure');

            return self::FAILURE;
        }

        $eventData = [
            'component' => 'spotify',
            'event' => 'spotify.webhook.test',
            'data' => [
                'message' => 'Webhook test ping',
                'hostname' => gethostname(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        $payload = json_encode($eventData);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $config['secret']);

        info("Sending test ping to {$config['url']}...");

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Spotify-CLI-Signature' => "sha256={$signature}",
                    'X-Spotify-CLI-Timestamp' => (string) $timestamp,
                    'X-Spotify-CLI-Event' => 'spotify.webhook.test',
                ])
                ->withBody($payload, 'application/json')
                ->post($config['url']);

            if ($response->successful()) {
                info("Webhook responded: {$response->status()}");

                return self::SUCCESS;
            }

            error("Webhook returned {$response->status()}");
            $this->line($response->body());

            return self::FAILURE;
        } catch (\Throwable $e) {
            error("Webhook failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
