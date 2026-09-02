<?php

use App\Services\Daemon\Process;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use Tests\DaemonHealSpyCommand;

describe('DevicesCommand', function (): void {

    beforeEach(function (): void {
        $this->previousHome = getenv('HOME') ?: '/tmp/spotify-cli-test';
        $this->tempDir = DaemonHealSpyCommand::isolateHome();
        DaemonHealSpyCommand::bind($this->app);
    });

    afterEach(function (): void {
        DaemonHealSpyCommand::restoreHome($this->previousHome, $this->tempDir);
    });

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

    it('transfers with --switch NAME and --json without prompting', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'thor-id',
                    'name' => 'Thor Speaker',
                    'type' => 'Speaker',
                    'is_active' => false,
                    'volume_percent' => 50,
                ],
            ]);
            $mock->shouldReceive('transferPlayback')->once()->with('thor-id');
        });

        $this->artisan('devices', ['--switch' => true, 'name' => 'Thor', '--json' => true])
            ->expectsOutputToContain('"device_id":"thor-id"')
            ->assertExitCode(0);
    });

    it('transfers with --device= and --json without --switch', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'thor-id',
                    'name' => 'Thor',
                    'type' => 'Speaker',
                    'is_active' => false,
                    'volume_percent' => 50,
                ],
            ]);
            $mock->shouldReceive('transferPlayback')->once()->with('thor-id');
        });

        $this->artisan('devices', ['--device' => 'Thor', '--json' => true])
            ->expectsOutputToContain('"success":true')
            ->assertExitCode(0);
    });

    it('uses the daemon device under --switch --json when no name is given', function (): void {
        DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');
        $this->mock(Process::class, function ($mock): void {
            $mock->shouldReceive('isAlive')->once()->andReturn(true);
        });
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'thor-id',
                    'name' => 'Thor',
                    'type' => 'Speaker',
                    'is_active' => false,
                    'volume_percent' => 50,
                ],
            ]);
            $mock->shouldReceive('transferPlayback')->once()->with('thor-id');
        });

        $this->artisan('devices', ['--switch' => true, '--json' => true])
            ->expectsOutputToContain('thor-id')
            ->assertExitCode(0);
    });

    it('does not prompt when --switch is non-interactive', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('transferPlayback')->never();
        });

        $this->artisan('devices', ['--switch' => true, '--no-interaction' => true])
            ->expectsOutputToContain('No active device')
            ->assertExitCode(1);
    });

    it('fails json --switch when no device can be resolved', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('transferPlayback')->never();
        });

        $this->artisan('devices', ['--switch' => true, '--json' => true])
            ->expectsOutputToContain('No active device. Pass a name or ID')
            ->assertExitCode(1);
    });

    it('lists json without transferring', function (): void {
        $devices = [
            [
                'id' => 'device1',
                'name' => 'MacBook Pro',
                'type' => 'Computer',
                'is_active' => true,
                'volume_percent' => 75,
            ],
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($devices): void {
            $mock->shouldReceive('getDevices')->once()->andReturn($devices);
            $mock->shouldReceive('transferPlayback')->never();
        });

        $this->artisan('devices', ['--json' => true])
            ->expectsOutputToContain('MacBook Pro')
            ->assertExitCode(0);
    });

});
