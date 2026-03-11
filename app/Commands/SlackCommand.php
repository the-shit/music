<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class SlackCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'slack
        {action=now : Action to perform (setup, now, test)}
        {--channel= : Slack channel for posting}';

    protected $description = 'Share what\'s playing to Slack';

    private SpotifyService $spotify;

    public function handle(SpotifyService $spotify): int
    {
        $this->spotify = $spotify;
        $action = $this->argument('action');

        return match ($action) {
            'setup' => $this->setup(),
            'now' => $this->shareNow(),
            'test' => $this->testWebhook(),
            default => $this->invalidAction($action),
        };
    }

    private function setup(): int
    {
        info('🔗 Slack Webhook Setup');
        $this->newLine();
        $this->line('1. Go to https://api.slack.com/apps');
        $this->line('2. Create app → "From scratch" → name it "Spotify DJ"');
        $this->line('3. Incoming Webhooks → Activate → Add New Webhook');
        $this->line('4. Pick your channel → Copy the webhook URL');
        $this->newLine();

        $webhookUrl = text(
            label: 'Paste your Slack webhook URL:',
            placeholder: 'https://hooks.slack.com/services/...',
            validate: fn (string $value): ?string => str_starts_with($value, 'https://hooks.slack.com/')
                ? null
                : 'Must be a valid Slack webhook URL'
        );

        $configDir = config('spotify.config_dir');
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        file_put_contents(
            $configDir.'/slack.json',
            json_encode(['webhook_url' => $webhookUrl], JSON_PRETTY_PRINT)
        );

        info('✅ Slack webhook saved!');
        info('Test it: spotify slack test');
        info('Share now playing: spotify slack now');
        info('Stream live: spotify watch --slack');

        return self::SUCCESS;
    }

    private function shareNow(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $webhook = $this->loadWebhook();
        if (! $webhook) {
            error('No Slack webhook configured. Run: spotify slack setup');

            return self::FAILURE;
        }
        $current = $this->spotify->getCurrentPlayback();

        if (! $current) {
            warning('Nothing is currently playing.');

            return self::SUCCESS;
        }

        $response = Http::post($webhook, [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ":musical_note: *Now Playing*\n*{$current['name']}*\n{$current['artist']} — _{$current['album']}_",
                    ],
                ],
            ],
        ]);

        if ($response->successful()) {
            info("📡 Shared to Slack: {$current['name']} by {$current['artist']}");
        } else {
            error('Failed to post to Slack. Check your webhook URL.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function testWebhook(): int
    {
        $webhook = $this->loadWebhook();
        if (! $webhook) {
            error('No Slack webhook configured. Run: spotify slack setup');

            return self::FAILURE;
        }

        $response = Http::post($webhook, [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ":white_check_mark: *Spotify CLI connected!*\nNow-playing updates will appear here.",
                    ],
                ],
            ],
        ]);

        if ($response->successful()) {
            info('✅ Slack webhook works! Check your channel.');
        } else {
            error('❌ Webhook test failed. Double-check the URL.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        error("Invalid action: {$action}");
        info('Valid actions: setup, now, test');

        return self::FAILURE;
    }

    private function loadWebhook(): ?string
    {
        $configDir = config('spotify.config_dir');
        $configFile = $configDir.'/slack.json';

        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);

            return $config['webhook_url'] ?? null;
        }

        return env('SPOTIFY_SLACK_WEBHOOK');
    }
}
