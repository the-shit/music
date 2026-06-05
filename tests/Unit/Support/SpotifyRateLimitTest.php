<?php

use App\Support\SpotifyRateLimit;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    // The breaker persists next to the token file — point it at a fresh temp dir.
    $this->configDir = sys_get_temp_dir().'/spotify-rate-limit-test-'.uniqid();
    mkdir($this->configDir, 0755, true);
    Config::set('spotify.token_path', $this->configDir.'/token.json');
});

afterEach(function (): void {
    SpotifyRateLimit::clear();
    @rmdir($this->configDir);
});

describe('SpotifyRateLimit', function (): void {

    it('is inactive when no deadline has been persisted', function (): void {
        expect(SpotifyRateLimit::active())->toBeFalse();
        expect(SpotifyRateLimit::resumesAt())->toBeNull();
    });

    it('persists the Retry-After deadline on hit and reports active', function (): void {
        SpotifyRateLimit::hit('120');

        expect(SpotifyRateLimit::active())->toBeTrue();
        expect(SpotifyRateLimit::resumesAt())
            ->toBeGreaterThanOrEqual(time() + 118)
            ->toBeLessThanOrEqual(time() + 122);
        expect(file_exists($this->configDir.'/rate-limit.json'))->toBeTrue();
    });

    it('falls back to a default cool-down when Retry-After is missing', function (): void {
        SpotifyRateLimit::hit(null);

        expect(SpotifyRateLimit::resumesAt())
            ->toBeGreaterThanOrEqual(time() + 58)
            ->toBeLessThanOrEqual(time() + 62);
    });

    it('expires automatically once the deadline passes and removes the file', function (): void {
        file_put_contents($this->configDir.'/rate-limit.json', json_encode(['resumes_at' => time() - 5]));

        expect(SpotifyRateLimit::active())->toBeFalse();
        expect(SpotifyRateLimit::resumesAt())->toBeNull();
        expect(file_exists($this->configDir.'/rate-limit.json'))->toBeFalse();
    });

    it('survives garbage in the file without throwing', function (): void {
        file_put_contents($this->configDir.'/rate-limit.json', 'not json');

        expect(SpotifyRateLimit::active())->toBeFalse();
    });

    it('clears the persisted deadline', function (): void {
        SpotifyRateLimit::hit('300');
        SpotifyRateLimit::clear();

        expect(SpotifyRateLimit::active())->toBeFalse();
    });

    describe('guard()', function (): void {

        it('short-circuits without invoking the request while active', function (): void {
            SpotifyRateLimit::hit('300');
            $invoked = false;

            $result = SpotifyRateLimit::guard(function () use (&$invoked): Response {
                $invoked = true;

                return new Response(new Psr7Response(200));
            });

            expect($result)->toBeNull();
            expect($invoked)->toBeFalse();
        });

        it('passes successful responses through and stays closed', function (): void {
            $result = SpotifyRateLimit::guard(fn (): Response => new Response(new Psr7Response(200, [], '{}')));

            expect($result)->not->toBeNull();
            expect($result->successful())->toBeTrue();
            expect(SpotifyRateLimit::active())->toBeFalse();
        });

        it('trips the breaker when the response is a 429', function (): void {
            $result = SpotifyRateLimit::guard(
                fn (): Response => new Response(new Psr7Response(429, ['Retry-After' => '120']))
            );

            expect($result->status())->toBe(429);
            expect(SpotifyRateLimit::active())->toBeTrue();
            expect(SpotifyRateLimit::resumesAt())->toBeGreaterThan(time() + 100);
        });
    });
});
