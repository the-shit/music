<?php

declare(strict_types=1);

use App\Player\PlayerViewModel;

/**
 * WHY: PlayerViewModel is the contract every other player lane (Theme, Renderer,
 * the php-tui loop) codes against, so its mapping and guards must be bulletproof.
 * These tests pin the exact shape returned by SpotifyPlayerService::getCurrentPlayback()
 * and every edge that has historically caused crashes/garbage in terminal UIs:
 * missing album, zero duration, null volume, and the empty/no-playback state.
 */

/**
 * A full, realistic getCurrentPlayback() payload. Mirrors the real service shape:
 * track fields at the top level, volume + name nested under `device`.
 */
function playbackPayload(array $overrides = []): array
{
    return array_merge([
        'uri' => 'spotify:track:abc',
        'name' => 'Bohemian Rhapsody',
        'track' => 'Bohemian Rhapsody',
        'artist' => 'Queen',
        'artist_id' => 'queen-id',
        'album' => 'A Night at the Opera',
        'album_art_url' => 'https://example.test/art.jpg',
        'progress_ms' => 83_000,
        'duration_ms' => 296_000,
        'is_playing' => true,
        'shuffle_state' => true,
        'repeat_state' => 'context',
        'device' => [
            'id' => 'device-1',
            'name' => 'Living Room',
            'volume_percent' => 65,
        ],
    ], $overrides);
}

describe('PlayerViewModel::fromPlayback mapping', function (): void {
    it('maps a full playback payload into the view model', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload());

        expect($vm->hasPlayback)->toBeTrue();
        expect($vm->title)->toBe('Bohemian Rhapsody');
        expect($vm->artist)->toBe('Queen');
        expect($vm->album)->toBe('A Night at the Opera');
        expect($vm->isPlaying)->toBeTrue();
        expect($vm->progressMs)->toBe(83_000);
        expect($vm->durationMs)->toBe(296_000);
        expect($vm->volume)->toBe(65);
        expect($vm->shuffle)->toBeTrue();
        expect($vm->repeat)->toBe('context');
        expect($vm->deviceName)->toBe('Living Room');
    });

    it('reads volume and device name from the nested device object', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'device' => ['name' => 'Phone', 'volume_percent' => 10],
        ]));

        expect($vm->volume)->toBe(10);
        expect($vm->deviceName)->toBe('Phone');
    });

    it('falls back to the track key when name is absent', function (): void {
        $payload = playbackPayload();
        unset($payload['name']);

        $vm = PlayerViewModel::fromPlayback($payload);

        expect($vm->title)->toBe('Bohemian Rhapsody');
    });

    it('coerces volume_percent to an int', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'device' => ['name' => 'TV', 'volume_percent' => '42'],
        ]));

        expect($vm->volume)->toBe(42);
    });
});

describe('PlayerViewModel edge cases', function (): void {
    it('reports no playback for null', function (): void {
        $vm = PlayerViewModel::fromPlayback(null);

        expect($vm->hasPlayback)->toBeFalse();
        expect($vm->title)->toBe('');
        expect($vm->artist)->toBe('');
        expect($vm->album)->toBe('');
        expect($vm->isPlaying)->toBeFalse();
        expect($vm->volume)->toBeNull();
        expect($vm->deviceName)->toBeNull();
        expect($vm->repeat)->toBe('off');
    });

    it('reports no playback for an empty array', function (): void {
        $vm = PlayerViewModel::fromPlayback([]);

        expect($vm->hasPlayback)->toBeFalse();
        expect($vm->durationMs)->toBe(0);
    });

    it('defaults album to Unknown when missing', function (): void {
        $payload = playbackPayload();
        unset($payload['album']);

        $vm = PlayerViewModel::fromPlayback($payload);

        expect($vm->album)->toBe('Unknown');
    });

    it('treats a missing device as null volume and device name', function (): void {
        $payload = playbackPayload();
        unset($payload['device']);

        $vm = PlayerViewModel::fromPlayback($payload);

        expect($vm->volume)->toBeNull();
        expect($vm->deviceName)->toBeNull();
    });

    it('treats a device without a volume as null volume', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'device' => ['name' => 'Speaker'],
        ]));

        expect($vm->volume)->toBeNull();
        expect($vm->deviceName)->toBe('Speaker');
    });
});

describe('PlayerViewModel::progressFraction', function (): void {
    it('computes the elapsed fraction', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'progress_ms' => 50_000,
            'duration_ms' => 100_000,
        ]));

        expect($vm->progressFraction())->toBe(0.5);
    });

    it('guards against zero duration', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'progress_ms' => 10_000,
            'duration_ms' => 0,
        ]));

        expect($vm->progressFraction())->toBe(0.0);
    });

    it('clamps an overshooting progress to 1.0', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'progress_ms' => 120_000,
            'duration_ms' => 100_000,
        ]));

        expect($vm->progressFraction())->toBe(1.0);
    });
});

describe('PlayerViewModel::progressLabel', function (): void {
    it('formats elapsed and total as m:ss', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'progress_ms' => 83_000,
            'duration_ms' => 296_000,
        ]));

        expect($vm->progressLabel())->toBe('1:23 / 4:56');
    });

    it('zero-pads seconds and renders zero state', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'progress_ms' => 5_000,
            'duration_ms' => 0,
        ]));

        expect($vm->progressLabel())->toBe('0:05 / 0:00');
    });
});

describe('PlayerViewModel::volumeFraction', function (): void {
    it('computes the volume fraction', function (): void {
        $vm = PlayerViewModel::fromPlayback(playbackPayload([
            'device' => ['name' => 'X', 'volume_percent' => 75],
        ]));

        expect($vm->volumeFraction())->toBe(0.75);
    });

    it('returns 0.0 when volume is null', function (): void {
        $payload = playbackPayload();
        unset($payload['device']);

        $vm = PlayerViewModel::fromPlayback($payload);

        expect($vm->volumeFraction())->toBe(0.0);
    });
});

describe('PlayerViewModel::repeatLabel', function (): void {
    it('labels off, track, and context', function (): void {
        expect(PlayerViewModel::fromPlayback(playbackPayload(['repeat_state' => 'off']))->repeatLabel())->toBe('Off');
        expect(PlayerViewModel::fromPlayback(playbackPayload(['repeat_state' => 'track']))->repeatLabel())->toBe('Track');
        expect(PlayerViewModel::fromPlayback(playbackPayload(['repeat_state' => 'context']))->repeatLabel())->toBe('All');
    });

    it('falls back to Off for an unknown repeat value', function (): void {
        expect(PlayerViewModel::fromPlayback(playbackPayload(['repeat_state' => 'weird']))->repeatLabel())->toBe('Off');
    });
});
