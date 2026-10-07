<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use App\Support\SpotifyRateLimit;
use Illuminate\Support\Facades\Config;

describe('DevicesCommand', function (): void {

    it('lists available devices', function (): void {
        $devices = [
            [
                'id' => 'device1',
                'name' => 'MacBook Pro',
                'type' => 'Computer',
                'is_active' => true,
                'volume_percent' => 75,
            ],
            [
                'id' => 'device2',
                'name' => 'iPhone',
                'type' => 'Smartphone',
                'is_active' => false,
                'volume_percent' => 50,
            ],
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($devices): void {
            $mock->shouldReceive('getDevices')->once()->andReturn($devices);
        });

        $this->artisan('devices')
            ->expectsOutputToContain('📱 Available Spotify Devices:')
            ->expectsOutputToContain('MacBook Pro')
            ->expectsOutputToContain('Computer')
            ->expectsOutputToContain('Volume: 75%')
            ->expectsOutputToContain('iPhone')
            ->expectsOutputToContain('Smartphone')
            ->expectsOutputToContain('Volume: 50%')
            ->assertExitCode(0);
    });

    it('handles no devices', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([]);
        });

        $this->artisan('devices')
            ->expectsOutputToContain('📱 No devices found')
            ->expectsOutputToContain('💡 Open Spotify on your phone, computer, or smart speaker')
            ->assertExitCode(0);
    });

    it('handles API errors', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')
                ->once()
                ->andThrow(new Exception('API error'));
        });

        $this->artisan('devices')
            ->expectsOutputToContain('❌ API error')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('devices')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    describe('when Spotify rate-limits the app', function (): void {
        beforeEach(function (): void {
            $this->rateDir = sys_get_temp_dir().'/spotify-devices-429-'.uniqid();
            mkdir($this->rateDir, 0755, true);
            Config::set('spotify.token_path', $this->rateDir.'/token.json');

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->once()->andReturn([]);
            });
        });

        afterEach(function (): void {
            SpotifyRateLimit::clear();
            @rmdir($this->rateDir);
        });

        it('reports the rate limit instead of "No devices found"', function (): void {
            SpotifyRateLimit::hit('3600');

            $this->artisan('devices')
                ->expectsOutputToContain('rate-limiting this app (HTTP 429)')
                ->doesntExpectOutputToContain('No devices found')
                ->assertExitCode(1);
        });

        it('returns a JSON error with --json', function (): void {
            SpotifyRateLimit::hit('3600');

            $this->artisan('devices', ['--json' => true])
                ->expectsOutputToContain('"error":"rate_limited"')
                ->assertExitCode(1);
        });

        it('prints an empty JSON array when there really are no devices', function (): void {
            $this->artisan('devices', ['--json' => true])
                ->expectsOutput('[]')
                ->assertExitCode(0);
        });
    });
});
