<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use Illuminate\Support\Facades\Artisan;

/**
 * Run one pulse heartbeat in --json mode and return the decoded object.
 *
 * pulse never prompts in --json mode, so we drive the otherwise-infinite loop
 * with --max-ticks=1 (a test-only guard documented in the signature). We assert
 * the decoded heartbeat rather than chaining many expectsOutputToContain calls:
 * the whole heartbeat is a single write, and Laravel routes each substring
 * matcher to only one write, so only one substring assertion can ever match.
 */
function pulseHeartbeat(): array
{
    $code = Artisan::call('pulse', ['--json' => true, '--max-ticks' => 1]);
    expect($code)->toBe(0);

    $line = collect(explode("\n", trim(Artisan::output())))
        ->map(fn (string $l): string => trim($l))
        ->first(fn (string $l): bool => str_starts_with($l, '{'));

    expect($line)->not->toBeNull();

    return json_decode((string) $line, true, flags: JSON_THROW_ON_ERROR);
}

describe('PulseCommand', function (): void {

    it('emits a single JSON heartbeat for the current playback', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'uri' => 'spotify:track:abc',
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
                'progress_ms' => 30000,
                'duration_ms' => 180000,
                'is_playing' => true,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'device' => ['name' => 'Living Room', 'volume_percent' => 55],
            ]);
        });

        $beat = pulseHeartbeat();

        // Exact schema from scratchpad #49 section A.
        expect($beat)->toHaveKeys([
            'type', 'ts', 'is_playing', 'track', 'artist', 'uri',
            'progress_ms', 'duration_ms', 'device', 'volume', 'today',
        ]);
        expect($beat['type'])->toBe('pulse');
        expect($beat['is_playing'])->toBeTrue();
        expect($beat['track'])->toBe('Test Song');
        expect($beat['artist'])->toBe('Test Artist');
        expect($beat['uri'])->toBe('spotify:track:abc');
        expect($beat['progress_ms'])->toBe(30000);
        expect($beat['duration_ms'])->toBe(180000);
        expect($beat['device'])->toBe('Living Room');
        expect($beat['volume'])->toBe(55);

        expect($beat['today'])->toHaveKeys([
            'listen_seconds', 'tracks_played', 'skips', 'top_track', 'top_artist',
        ]);
        // First track seen counts as one play; top_* resolve to it.
        expect($beat['today']['tracks_played'])->toBe(1);
        expect($beat['today']['skips'])->toBe(0);
        expect($beat['today']['top_track'])->toBe('Test Song');
        expect($beat['today']['top_artist'])->toBe('Test Artist');
    });

    it('emits a heartbeat even when nothing is playing so the pane never goes silent', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $beat = pulseHeartbeat();

        expect($beat['type'])->toBe('pulse');
        expect($beat['is_playing'])->toBeFalse();
        expect($beat['track'])->toBeNull();
        expect($beat['artist'])->toBeNull();
        expect($beat['uri'])->toBeNull();
        expect($beat['progress_ms'])->toBe(0);
        expect($beat['duration_ms'])->toBe(0);
        expect($beat['device'])->toBeNull();
        expect($beat['volume'])->toBeNull();
        expect($beat['today']['tracks_played'])->toBe(0);
        expect($beat['today']['top_track'])->toBeNull();
        expect($beat['today']['top_artist'])->toBeNull();
    });

    it('stays alive with an idle heartbeat when the player throws', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getCurrentPlayback')
                ->once()
                ->andThrow(new Exception('Spotify API exploded'));
        });

        $beat = pulseHeartbeat();

        // A failing tick must never crash the loop (auto_restart safety); it
        // degrades to a schema-valid idle heartbeat.
        expect($beat['type'])->toBe('pulse');
        expect($beat['is_playing'])->toBeFalse();
        expect($beat['track'])->toBeNull();
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('pulse', ['--json' => true, '--max-ticks' => 1])
            ->expectsOutputToContain('Spotify is not configured')
            ->assertExitCode(1);
    });

});
