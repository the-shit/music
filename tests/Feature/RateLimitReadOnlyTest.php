<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use App\Support\SpotifyRateLimit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/*
 * The token dir can be a READ-ONLY bind mount (Asgard runs the phar in a
 * rootless container with ~/.config/spotify-cli mounted :ro). A 429 there used
 * to crash with "file_put_contents(.../rate-limit.json): Failed to open
 * stream: Read-only file system" instead of reporting the rate limit.
 */

function rateLimitTempDir(string $suffix): string
{
    $dir = sys_get_temp_dir().'/spotify-ro-'.$suffix.'-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function rateLimitRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    @chmod($dir, 0755);

    foreach (glob($dir.'/*') ?: [] as $file) {
        is_dir($file) ? rateLimitRemoveDir($file) : @unlink($file);
    }

    @rmdir($dir);
}

beforeEach(function (): void {
    $this->roDir = rateLimitTempDir('config');
    $this->stateDir = rateLimitTempDir('state');
    Config::set('spotify.token_path', $this->roDir.'/token.json');
    Config::set('spotify.state_dir', $this->stateDir);
    SpotifyRateLimit::clear();
});

afterEach(function (): void {
    SpotifyRateLimit::clear();
    rateLimitRemoveDir($this->roDir);
    rateLimitRemoveDir($this->stateDir);
});

describe('SpotifyRateLimit on a read-only token dir', function (): void {

    it('records a 429 without throwing and falls back to the writable state dir', function (): void {
        chmod($this->roDir, 0555);

        expect(fn () => SpotifyRateLimit::hit('600'))->not->toThrow(Throwable::class);

        expect(file_exists($this->roDir.'/rate-limit.json'))->toBeFalse();
        expect(file_exists($this->stateDir.'/rate-limit.json'))->toBeTrue();
        expect(SpotifyRateLimit::active())->toBeTrue();
        expect(SpotifyRateLimit::resumesAt())->toBeGreaterThan(time() + 590);
    })->skip(fn (): bool => function_exists('posix_getuid') && posix_getuid() === 0, 'root ignores directory permissions');

    it('keeps an in-process deadline when no location is writable', function (): void {
        chmod($this->roDir, 0555);
        chmod($this->stateDir, 0555);

        expect(fn () => SpotifyRateLimit::hit('600'))->not->toThrow(Throwable::class);

        expect(SpotifyRateLimit::active())->toBeTrue();
        expect(SpotifyRateLimit::describe())
            ->toStartWith('Spotify is rate-limiting requests (HTTP 429); try again after ')
            ->toContain('(about 10 min)');
    })->skip(fn (): bool => function_exists('posix_getuid') && posix_getuid() === 0, 'root ignores directory permissions');

    it('honours an existing marker it cannot write or delete', function (): void {
        file_put_contents($this->roDir.'/rate-limit.json', json_encode(['resumes_at' => time() + 1800]));
        chmod($this->roDir, 0555);

        expect(SpotifyRateLimit::resumesAt())->toBeGreaterThan(time() + 1790);
        expect(SpotifyRateLimit::describe())->toContain('(about 30 min)');
    });

    it('short-circuits without any HTTP request while the marker is active', function (): void {
        file_put_contents($this->roDir.'/rate-limit.json', json_encode(['resumes_at' => time() + 1800]));
        chmod($this->roDir, 0555);
        Http::fake();

        $result = SpotifyRateLimit::guard(fn () => Http::get('https://api.spotify.com/v1/me/player/devices'));

        expect($result)->toBeNull();
        Http::assertNothingSent();
    });
});

describe('commands while Spotify rate-limits', function (): void {

    it('play reports the rate limit up front and sends nothing', function (): void {
        SpotifyRateLimit::hit('1800');

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock): void {
            $mock->shouldNotReceive('search');
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldNotReceive('getDevices');
            $mock->shouldNotReceive('play');
        });

        $this->artisan('play', ['query' => 'anything', '--device' => 'Kitchen'])
            ->expectsOutputToContain('Spotify is rate-limiting requests (HTTP 429); try again after')
            ->doesntExpectOutputToContain('No results found')
            ->assertExitCode(1);
    });

    it('play --json returns a rate_limited error', function (): void {
        SpotifyRateLimit::hit('1800');

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });

        $this->artisan('play', ['query' => 'anything', '--json' => true])
            ->expectsOutputToContain('"error":"rate_limited"')
            ->assertExitCode(1);
    });

    it('turns a live 429 on a read-only token dir into the graceful devices message', function (): void {
        chmod($this->roDir, 0555);
        Http::fake([
            'api.spotify.com/*' => Http::response(['error' => ['status' => 429]], 429, ['Retry-After' => '900']),
        ]);

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('requireAuth')->andReturnNull();
            $mock->shouldReceive('getAccessToken')->andReturn('test-token');
        });

        $this->artisan('devices')
            ->expectsOutputToContain('Spotify is rate-limiting requests (HTTP 429); try again after')
            ->doesntExpectOutputToContain('No devices found')
            ->doesntExpectOutputToContain('Read-only file system')
            ->assertExitCode(1);

        // One request tripped the breaker; nothing else went out.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'me/player/devices'));
        expect(SpotifyRateLimit::resumesAt())->toBeGreaterThan(time() + 890);
    });
});
