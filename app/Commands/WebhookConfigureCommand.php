<?php

namespace App\Commands;

use App\Helpers\ConfigHelper;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class WebhookConfigureCommand extends Command
{
    protected $signature = 'webhook:configure
                            {--url= : Webhook URL}
                            {--secret= : HMAC secret}
                            {--disable : Disable the webhook}
                            {--show : Show current configuration}';

    protected $description = 'Configure webhook for event forwarding';

    public function handle(): int
    {
        if ($this->option('show')) {
            return $this->showConfig();
        }

        if ($this->option('disable')) {
            return $this->disable();
        }

        return $this->configureWebhook();
    }

    private function showConfig(): int
    {
        $config = ConfigHelper::getWebhookConfig();

        if (empty($config['url'])) {
            warning('No webhook configured');
            info('Run: spotify webhook:configure');

            return self::SUCCESS;
        }

        info('Webhook Configuration:');
        $this->line("  URL:     {$config['url']}");
        $this->line('  Secret:  '.str_repeat('*', 20));
        $this->line('  Enabled: '.($config['enabled'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function disable(): int
    {
        $config = ConfigHelper::getWebhookConfig();

        if (empty($config['url'])) {
            warning('No webhook configured');

            return self::SUCCESS;
        }

        $config['enabled'] = false;
        ConfigHelper::saveWebhookConfig($config);
        info('Webhook disabled');

        return self::SUCCESS;
    }

    private function configureWebhook(): int
    {
        $url = $this->option('url') ?: text(
            label: 'Webhook URL',
            placeholder: 'https://example.com/webhook/spotify',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Must be a valid URL',
        );

        $secret = $this->option('secret') ?: password(
            label: 'HMAC Secret',
            placeholder: 'Your webhook signing secret',
            required: true,
            validate: fn (string $value) => strlen($value) >= 8 ? null : 'Secret must be at least 8 characters',
        );

        ConfigHelper::saveWebhookConfig([
            'url' => $url,
            'secret' => $secret,
            'enabled' => true,
        ]);

        info('Webhook configured');
        $this->line("  URL: {$url}");

        if (confirm('Send a test ping?', true)) {
            return $this->call('webhook:test');
        }

        return self::SUCCESS;
    }
}
