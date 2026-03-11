<?php

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Http;

it('shares now playing to slack', function (): void {
    $configDir = sys_get_temp_dir().'/spotify-slack-test';
    if (! is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configDir.'/slack.json', json_encode([
        'webhook_url' => 'https://hooks.slack.com/services/TEST/TEST/TEST',
    ]));

    config(['spotify.config_dir' => $configDir]);

    Http::fake([
        'hooks.slack.com/*' => Http::response('ok'),
    ]);

    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $mock->shouldReceive('getCurrentPlayback')->andReturn([
        'name' => 'Test Track',
        'artist' => 'Test Artist',
        'album' => 'Test Album',
        'is_playing' => true,
    ]);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('slack', ['action' => 'now'])
        ->expectsOutputToContain('Shared to Slack')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.slack.com'));

    // Cleanup
    @unlink($configDir.'/slack.json');
    @rmdir($configDir);
});

it('tests webhook connectivity', function (): void {
    $configDir = sys_get_temp_dir().'/spotify-slack-test2';
    if (! is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configDir.'/slack.json', json_encode([
        'webhook_url' => 'https://hooks.slack.com/services/TEST/TEST/TEST',
    ]));

    config(['spotify.config_dir' => $configDir]);

    Http::fake([
        'hooks.slack.com/*' => Http::response('ok'),
    ]);

    $this->artisan('slack', ['action' => 'test'])
        ->expectsOutputToContain('Slack webhook works')
        ->assertSuccessful();

    // Cleanup
    @unlink($configDir.'/slack.json');
    @rmdir($configDir);
});

it('fails when no webhook configured', function (): void {
    $configDir = sys_get_temp_dir().'/spotify-slack-empty';
    config(['spotify.config_dir' => $configDir]);

    $mock = Mockery::mock(SpotifyService::class);
    $mock->shouldReceive('isConfigured')->andReturn(true);
    $this->app->instance(SpotifyService::class, $mock);

    $this->artisan('slack', ['action' => 'now'])
        ->assertFailed();
});

it('handles invalid action', function (): void {
    $this->artisan('slack', ['action' => 'invalid'])
        ->assertFailed();
});
