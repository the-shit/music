<?php

use App\Services\Daemon\Process;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use App\Support\SpotifyRateLimit;
use App\Support\Stdin;
use Illuminate\Support\Facades\Config;
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

    it('uses the daemon device for a piped --switch without calling select', function (): void {
        // Symfony keeps Input interactive when stdin is a pipe. select()
        // then spins on EOF. A non-TTY must transfer to the daemon instead.
        $this->mock(Stdin::class, function ($mock): void {
            $mock->shouldReceive('isTty')->once()->andReturn(false);
        });
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
                    'name' => 'Thor Speaker',
                    'type' => 'Speaker',
                    'is_active' => false,
                    'volume_percent' => 50,
                ],
            ]);
            $mock->shouldReceive('transferPlayback')->once()->with('thor-id');
        });

        $this->artisan('devices', ['--switch' => true])
            ->expectsOutputToContain('Playback transferred')
            ->assertExitCode(0);
    });

    it('does not call select under --json even when stdin is a tty', function (): void {
        $this->mock(Stdin::class, function ($mock): void {
            $mock->shouldReceive('isTty')->once()->andReturn(true);
        });
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

    it('still prompts on an interactive tty when --switch has no name', function (): void {
        $this->mock(Stdin::class, function ($mock): void {
            $mock->shouldReceive('isTty')->once()->andReturn(true);
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
                    'volume_percent' => 40,
                ],
                [
                    'id' => 'phone-id',
                    'name' => 'Phone',
                    'type' => 'Smartphone',
                    'is_active' => true,
                    'volume_percent' => 20,
                ],
            ]);
            $mock->shouldReceive('transferPlayback')->once()->with('thor-id');
        });

        $this->artisan('devices', ['--switch' => true])
            ->expectsQuestion('🎵 Select a device to switch to:', 'thor-id')
            ->expectsOutputToContain('Playback transferred')
            ->assertExitCode(0);
    });

    it('transfers to a device id with --switch and --json', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'abc123',
                    'name' => 'Kitchen',
                    'type' => 'Speaker',
                    'is_active' => false,
                    'volume_percent' => 30,
                ],
            ]);
            $mock->shouldReceive('transferPlayback')->once()->with('abc123');
        });

        $this->artisan('devices --switch abc123 --json')
            ->expectsOutputToContain('"device_id":"abc123"')
            ->assertExitCode(0);
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
