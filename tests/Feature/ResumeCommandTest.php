<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

describe('ResumeCommand', function (): void {

    it('resumes playback without device specified', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('resume')->once();
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ]);
        });

        $this->artisan('resume')
            ->expectsOutputToContain('▶️  Resuming Spotify playback...')
            ->expectsOutputToContain('🎵 Resumed: Test Song by Test Artist')
            ->expectsOutputToContain('✅ Playback resumed!')
            ->assertExitCode(0);
    });

    it('resumes playback on specified device by name', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-123', 'name' => 'Living Room Speaker'],
                ['id' => 'device-456', 'name' => 'Kitchen Speaker'],
            ]);
            $mock->shouldReceive('transferPlayback')
                ->once()
                ->with('device-123', true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ]);
        });

        $this->artisan('resume', ['--device' => 'Living Room'])
            ->expectsOutputToContain('🔊 Using device: Living Room Speaker')
            ->expectsOutputToContain('▶️  Resuming Spotify playback...')
            ->expectsOutputToContain('🎵 Resumed: Test Song by Test Artist')
            ->expectsOutputToContain('✅ Playback resumed!')
            ->assertExitCode(0);
    });

    it('resumes playback on specified device by ID', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-123', 'name' => 'Living Room Speaker'],
                ['id' => 'device-456', 'name' => 'Kitchen Speaker'],
            ]);
            $mock->shouldReceive('transferPlayback')
                ->once()
                ->with('device-456', true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ]);
        });

        $this->artisan('resume', ['--device' => 'device-456'])
            ->expectsOutputToContain('🔊 Using device: Kitchen Speaker')
            ->expectsOutputToContain('▶️  Resuming Spotify playback...')
            ->expectsOutputToContain('🎵 Resumed: Test Song by Test Artist')
            ->expectsOutputToContain('✅ Playback resumed!')
            ->assertExitCode(0);
    });

    it('fails when specified device is not found', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-123', 'name' => 'Living Room Speaker'],
            ]);
        });

        $this->artisan('resume', ['--device' => 'Nonexistent Device'])
            ->expectsOutputToContain("Device 'Nonexistent Device' not found")
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('resume')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

    it('handles API errors', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('resume')
                ->once()
                ->andThrow(new Exception('No active device'));
        });

        $this->artisan('resume')
            ->expectsOutputToContain('▶️  Resuming Spotify playback...')
            ->expectsOutputToContain('Failed to resume: No active device')
            ->assertExitCode(1);
    });

    it('handles transfer playback errors when device specified', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-123', 'name' => 'Living Room Speaker'],
            ]);
            $mock->shouldReceive('transferPlayback')
                ->once()
                ->andThrow(new Exception('Device not responding'));
        });

        $this->artisan('resume', ['--device' => 'Living Room'])
            ->expectsOutputToContain('🔊 Using device: Living Room Speaker')
            ->expectsOutputToContain('▶️  Resuming Spotify playback...')
            ->expectsOutputToContain('Failed to resume: Device not responding')
            ->assertExitCode(1);
    });

    it('resumes without current track info', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('resume')->once();
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $this->artisan('resume')
            ->expectsOutputToContain('▶️  Resuming Spotify playback...')
            ->expectsOutputToContain('✅ Playback resumed!')
            ->assertExitCode(0);
    });

    describe('JSON output mode', function (): void {

        it('outputs JSON on successful resume without device', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('resume')->once();
                $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                ]);
            });

            $this->artisan('resume', ['--json' => true])
                ->expectsOutputToContain(json_encode([
                    'success' => true,
                    'resumed' => true,
                    'device_id' => null,
                    'track' => [
                        'name' => 'Test Song',
                        'artist' => 'Test Artist',
                        'album' => 'Test Album',
                    ],
                ]))
                ->assertExitCode(0);
        });

        it('outputs JSON on successful resume with device', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->once()->andReturn([
                    ['id' => 'device-123', 'name' => 'Living Room Speaker'],
                ]);
                $mock->shouldReceive('transferPlayback')
                    ->once()
                    ->with('device-123', true);
                $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                ]);
            });

            $this->artisan('resume', ['--device' => 'Living Room', '--json' => true])
                ->expectsOutputToContain('🔊 Using device: Living Room Speaker')
                ->expectsOutputToContain(json_encode([
                    'success' => true,
                    'resumed' => true,
                    'device_id' => 'device-123',
                    'track' => [
                        'name' => 'Test Song',
                        'artist' => 'Test Artist',
                        'album' => 'Test Album',
                    ],
                ]))
                ->assertExitCode(0);
        });

        it('outputs JSON with null track when no current playback', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('resume')->once();
                $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
            });

            $this->artisan('resume', ['--json' => true])
                ->expectsOutputToContain(json_encode([
                    'success' => true,
                    'resumed' => true,
                    'device_id' => null,
                    'track' => null,
                ]))
                ->assertExitCode(0);
        });

        it('outputs JSON error on failure', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('resume')
                    ->once()
                    ->andThrow(new Exception('Player command failed'));
            });

            $this->artisan('resume', ['--json' => true])
                ->expectsOutputToContain(json_encode([
                    'success' => false,
                    'error' => 'Player command failed',
                ]))
                ->assertExitCode(1);
        });

        it('does not output resuming message in JSON mode', function (): void {
            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('resume')->once();
                $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                    'name' => 'Test Song',
                    'artist' => 'Test Artist',
                    'album' => 'Test Album',
                ]);
            });

            $this->artisan('resume', ['--json' => true])
                ->doesntExpectOutput('▶️  Resuming Spotify playback...')
                ->doesntExpectOutput('✅ Playback resumed!')
                ->assertExitCode(0);
        });

    });

    it('uses case-insensitive device name matching', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-123', 'name' => 'Living Room Speaker'],
            ]);
            $mock->shouldReceive('transferPlayback')
                ->once()
                ->with('device-123', true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ]);
        });

        $this->artisan('resume', ['--device' => 'living room'])
            ->expectsOutputToContain('🔊 Using device: Living Room Speaker')
            ->assertExitCode(0);
    });

    it('matches partial device names', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-123', 'name' => 'Living Room Speaker'],
                ['id' => 'device-456', 'name' => 'Kitchen Speaker'],
            ]);
            $mock->shouldReceive('transferPlayback')
                ->once()
                ->with('device-456', true);
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
            ]);
        });

        $this->artisan('resume', ['--device' => 'Kitchen'])
            ->expectsOutputToContain('🔊 Using device: Kitchen Speaker')
            ->assertExitCode(0);
    });

});
