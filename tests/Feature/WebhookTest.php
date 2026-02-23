<?php

use App\Helpers\ConfigHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

describe('Webhook', function () {

    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/spotify-webhook-test-'.uniqid();
        mkdir($this->tempDir.'/.config/spotify-cli', 0755, true);
        $_SERVER['HOME'] = $this->tempDir;
        Config::set('spotify.config_dir', $this->tempDir.'/.config/spotify-cli');
    });

    afterEach(function () {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($this->tempDir);
        }
    });

    describe('ConfigHelper webhook methods', function () {

        it('returns defaults when no webhook.json exists', function () {
            $config = ConfigHelper::getWebhookConfig();
            expect($config['url'])->toBeNull();
            expect($config['secret'])->toBeNull();
            expect($config['enabled'])->toBeFalse();
        });

        it('returns false for hasWebhook when not configured', function () {
            expect(ConfigHelper::hasWebhook())->toBeFalse();
        });

        it('saves and reads webhook config', function () {
            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => true,
            ]);

            $config = ConfigHelper::getWebhookConfig();
            expect($config['url'])->toBe('https://example.com/hook');
            expect($config['secret'])->toBe('test-secret-123');
            expect($config['enabled'])->toBeTrue();
        });

        it('reports hasWebhook true when configured and enabled', function () {
            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => true,
            ]);

            expect(ConfigHelper::hasWebhook())->toBeTrue();
        });

        it('reports hasWebhook false when disabled', function () {
            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => false,
            ]);

            expect(ConfigHelper::hasWebhook())->toBeFalse();
        });

        it('secures webhook.json with 0600 permissions', function () {
            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'secret',
                'enabled' => true,
            ]);

            $file = ConfigHelper::configDir().'/webhook.json';
            $perms = substr(sprintf('%o', fileperms($file)), -4);
            expect($perms)->toBe('0600');
        });

    });

    describe('HMAC signature', function () {

        it('produces consistent signatures', function () {
            $payload = '{"event":"test"}';
            $timestamp = 1234567890;
            $secret = 'my-secret';

            $sig1 = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
            $sig2 = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

            expect($sig1)->toBe($sig2);
        });

        it('produces different signatures for different secrets', function () {
            $payload = '{"event":"test"}';
            $timestamp = 1234567890;

            $sig1 = hash_hmac('sha256', "{$timestamp}.{$payload}", 'secret-1');
            $sig2 = hash_hmac('sha256', "{$timestamp}.{$payload}", 'secret-2');

            expect($sig1)->not->toBe($sig2);
        });

    });

    describe('EventEmitCommand with webhook', function () {

        it('forwards events to webhook when configured', function () {
            Http::fake(['*' => Http::response('ok', 200)]);

            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => true,
            ]);

            Config::set('app.events_file', $this->tempDir.'/.config/spotify-cli/events.jsonl');

            $this->artisan('event:emit', ['event' => 'test.webhook', 'data' => '{"foo":"bar"}'])
                ->assertExitCode(0);

            Http::assertSentCount(1);
            Http::assertSent(function ($request) {
                return $request->url() === 'https://example.com/hook'
                    && $request->hasHeader('X-Spotify-CLI-Signature')
                    && $request->hasHeader('X-Spotify-CLI-Timestamp')
                    && $request->header('X-Spotify-CLI-Event')[0] === 'spotify.test.webhook';
            });
        });

        it('does not call webhook when not configured', function () {
            Http::fake();

            Config::set('app.events_file', $this->tempDir.'/.config/spotify-cli/events.jsonl');

            $this->artisan('event:emit', ['event' => 'test.no-hook', 'data' => '{}'])
                ->assertExitCode(0);

            Http::assertNothingSent();
        });

        it('logs errors on webhook failure without blocking', function () {
            Http::fake(['*' => Http::response('error', 500)]);

            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => true,
            ]);

            Config::set('app.events_file', $this->tempDir.'/.config/spotify-cli/events.jsonl');

            // Should succeed even though webhook returns 500 (fire-and-forget)
            $this->artisan('event:emit', ['event' => 'test.fail', 'data' => '{}'])
                ->assertExitCode(0);
        });

    });

    describe('webhook:configure command', function () {

        it('shows no config when unconfigured', function () {
            $this->artisan('webhook:configure', ['--show' => true])
                ->expectsOutputToContain('No webhook configured')
                ->assertExitCode(0);
        });

        it('shows current config when configured', function () {
            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'secret-123',
                'enabled' => true,
            ]);

            $this->artisan('webhook:configure', ['--show' => true])
                ->expectsOutputToContain('https://example.com/hook')
                ->expectsOutputToContain('Enabled: yes')
                ->assertExitCode(0);
        });

        it('disables webhook', function () {
            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'secret-123',
                'enabled' => true,
            ]);

            $this->artisan('webhook:configure', ['--disable' => true])
                ->expectsOutputToContain('Webhook disabled')
                ->assertExitCode(0);

            expect(ConfigHelper::hasWebhook())->toBeFalse();
        });

    });

    describe('webhook:test command', function () {

        it('fails when no webhook configured', function () {
            $this->artisan('webhook:test')
                ->expectsOutputToContain('No webhook configured')
                ->assertExitCode(1);
        });

        it('sends test ping and reports success', function () {
            Http::fake(['*' => Http::response('ok', 200)]);

            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => true,
            ]);

            $this->artisan('webhook:test')
                ->assertExitCode(0);

            Http::assertSent(function ($request) {
                return $request->header('X-Spotify-CLI-Event')[0] === 'spotify.webhook.test';
            });
        });

        it('reports failure on non-200 response', function () {
            Http::fake(['*' => Http::response('not found', 404)]);

            ConfigHelper::saveWebhookConfig([
                'url' => 'https://example.com/hook',
                'secret' => 'test-secret-123',
                'enabled' => true,
            ]);

            $this->artisan('webhook:test')
                ->assertExitCode(1);
        });

    });

});
