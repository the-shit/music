<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use App\Support\SpotifyRateLimit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Service-level proof of the 429 circuit breaker: one rate-limited response
 * opens the breaker, and every subsequent Spotify Web API call returns its
 * graceful empty WITHOUT touching the network — the polling panes stop digging
 * the hole deeper the moment Spotify says back off.
 */
beforeEach(function (): void {
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');

    $this->configDir = sys_get_temp_dir().'/spotify-breaker-test-'.uniqid();
    mkdir($this->configDir, 0755, true);
    Config::set('spotify.token_path', $this->configDir.'/token.json');

    $mockAuth = Mockery::mock(SpotifyAuthManager::class)->makePartial();
    $mockAuth->shouldReceive('getAccessToken')->andReturn('valid_token');
    $mockAuth->shouldReceive('requireAuth')->andReturn(null);

    $this->playerService = new SpotifyPlayerService($mockAuth);
    $this->discoveryService = new SpotifyDiscoveryService($mockAuth);
});

afterEach(function (): void {
    SpotifyRateLimit::clear();
    @rmdir($this->configDir);
});

describe('Spotify 429 circuit breaker', function (): void {

    it('opens the breaker on a 429 and short-circuits subsequent calls', function (): void {
        Http::fake([
            '*/me/player*' => Http::response('', 429, ['Retry-After' => '120']),
        ]);

        // First poll takes the 429 on the chin — and records the deadline.
        expect($this->playerService->getCurrentPlayback())->toBeNull();
        expect(SpotifyRateLimit::active())->toBeTrue();
        expect(SpotifyRateLimit::resumesAt())->toBeGreaterThan(time() + 100);

        // Every further poll returns its graceful empty WITHOUT a request.
        expect($this->playerService->getCurrentPlayback())->toBeNull();
        expect($this->playerService->getDevices())->toBe([]);
        expect($this->playerService->getQueue())->toBe([]);

        Http::assertSentCount(1);
    });

    it('short-circuits the discovery service while the breaker is open', function (): void {
        SpotifyRateLimit::hit('300');

        Http::fake();

        expect($this->discoveryService->search('test'))->toBeNull();
        expect($this->discoveryService->getTopTracks())->toBe([]);
        expect($this->discoveryService->getPlaylists())->toBe([]);

        Http::assertNothingSent();
    });

    it('degrades control toggles to false instead of hitting the network', function (): void {
        SpotifyRateLimit::hit('300');

        Http::fake();

        expect($this->playerService->setVolume(50))->toBeFalse();
        expect($this->playerService->setShuffle(true))->toBeFalse();

        Http::assertNothingSent();
    });

    it('resumes real requests once the deadline expires', function (): void {
        file_put_contents($this->configDir.'/rate-limit.json', json_encode(['resumes_at' => time() - 1]));

        Http::fake([
            '*/me/player/devices*' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Mac']]]),
        ]);

        expect($this->playerService->getDevices())->toHaveCount(1);
        expect(SpotifyRateLimit::active())->toBeFalse();

        Http::assertSentCount(1);
    });
});
