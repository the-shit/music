<?php

use App\Services\Daemon\Process;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use Tests\DaemonHealSpyCommand;

describe('PlayCommand', function (): void {

    beforeEach(function (): void {
        $this->previousHome = getenv('HOME') ?: '/tmp/spotify-cli-test';
        $this->tempDir = DaemonHealSpyCommand::isolateHome();
        DaemonHealSpyCommand::bind($this->app);
    });

    afterEach(function (): void {
        DaemonHealSpyCommand::restoreHome($this->previousHome, $this->tempDir);
    });

    it('searches and plays a track', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('search')
                ->once()
                ->with('test song')
                ->andReturn($searchResult);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('play')
                ->once()
                ->with('spotify:track:123', null);
        });

        $this->artisan('play', ['query' => 'test song'])
            ->expectsOutputToContain('🎵 Searching for: test song')
            ->expectsOutputToContain('▶️  Playing: Test Song by Test Artist')
            ->expectsOutputToContain('✅ Playback started!')
            ->assertExitCode(0);
    });

    it('handles no search results', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock): void {
            $mock->shouldReceive('search')
                ->once()
                ->with('nonexistent song')
                ->andReturn(null);
        });

        $this->artisan('play', ['query' => 'nonexistent song'])
            ->expectsOutputToContain('🎵 Searching for: nonexistent song')
            ->expectsOutputToContain('No results found for: nonexistent song')
            ->assertExitCode(1);
    });

    it('handles API errors gracefully', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('search')
                ->once()
                ->with('test song')
                ->andReturn($searchResult);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('play')
                ->once()
                ->with('spotify:track:123', null)
                ->andThrow(new Exception('No active device'));
        });

        $this->artisan('play', ['query' => 'test song'])
            ->expectsOutputToContain('🎵 Searching for: test song')
            ->expectsOutputToContain('Failed to play: No active device')
            ->assertExitCode(1);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('play', ['query' => 'test'])
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

    it('plays with --json flag suppresses normal output', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:abc',
            'name' => 'JSON Song',
            'artist' => 'JSON Artist',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('play')->once()->with('spotify:track:abc', null);
        });

        $this->artisan('play', ['query' => 'test', '--json' => true])
            ->doesntExpectOutput('🎵 Searching for: test')
            ->doesntExpectOutput('▶️  Playing: JSON Song by JSON Artist')
            ->assertExitCode(0);
    });

    it('adds to queue with --queue flag', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:456',
            'name' => 'Queue Song',
            'artist' => 'Queue Artist',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:456');
        });

        $this->artisan('play', ['query' => 'test', '--queue' => true])
            ->expectsOutputToContain('Added to queue: Queue Song by Queue Artist')
            ->assertExitCode(0);
    });

    it('adds to queue with --queue and --json flags', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:789',
            'name' => 'Queue JSON Song',
            'artist' => 'Queue Artist',
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:789');
        });

        $this->artisan('play', ['query' => 'test', '--queue' => true, '--json' => true])
            ->assertExitCode(0);
    });

    it('outputs json on no search results with --json flag', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock): void {
            $mock->shouldReceive('search')->once()->andReturn(null);
        });

        $this->artisan('play', ['query' => 'nothing', '--json' => true])
            ->assertExitCode(1);
    });

    it('outputs json error on API exception with --json flag', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock): void {
            $mock->shouldReceive('search')->once()->andThrow(new Exception('API down'));
        });

        $this->artisan('play', ['query' => 'test', '--json' => true])
            ->assertExitCode(1);
    });

    it('finds device by name and plays on it', function (): void {
        $searchResult = [
            'uri' => 'spotify:track:device1',
            'name' => 'Device Song',
            'artist' => 'Artist',
        ];

        $devices = [
            ['id' => 'device-id-123', 'name' => 'Living Room Speaker'],
        ];

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
            $mock->shouldReceive('search')->once()->andReturn($searchResult);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($devices): void {
            $mock->shouldReceive('getDevices')->once()->andReturn($devices);
            $mock->shouldReceive('play')->once()->with('spotify:track:device1', 'device-id-123');
        });

        $this->artisan('play', ['query' => 'test', '--device' => 'Living Room'])
            ->expectsOutputToContain('Using device: Living Room Speaker')
            ->assertExitCode(0);
    });

    it('fails when specified device is not found', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                ['id' => 'device-1', 'name' => 'Kitchen'],
            ]);
        });

        $this->artisan('play', ['query' => 'test', '--device' => 'nonexistent'])
            ->expectsOutputToContain("Device 'nonexistent' not found")
            ->assertExitCode(1);
    });

    describe('daemon heal', function (): void {

        it('heals the daemon when its Connect device is missing then plays on retry', function (): void {
            DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');

            $searchResult = [
                'uri' => 'spotify:track:heal1',
                'name' => 'Heal Song',
                'artist' => 'Artist',
            ];

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
                $mock->shouldReceive('search')->once()->andReturn($searchResult);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->twice()->andReturn(
                    [],
                    [['id' => 'daemon-id', 'name' => 'Thor']],
                );
                $mock->shouldReceive('play')->once()->with('spotify:track:heal1', 'daemon-id');
            });

            $this->artisan('play', ['query' => 'test'])
                ->expectsOutputToContain('Using daemon device: Thor')
                ->assertExitCode(0);

            expect(DaemonHealSpyCommand::$calls)->toBe([
                ['action' => 'health', 'heal' => true],
            ]);
        });

        it('heals silently under --json', function (): void {
            DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');

            $searchResult = [
                'uri' => 'spotify:track:healjson',
                'name' => 'JSON Heal',
                'artist' => 'Artist',
            ];

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
                $mock->shouldReceive('search')->once()->andReturn($searchResult);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->twice()->andReturn(
                    [],
                    [['id' => 'daemon-id', 'name' => 'Thor']],
                );
                $mock->shouldReceive('play')->once()->with('spotify:track:healjson', 'daemon-id');
            });

            $this->artisan('play', ['query' => 'test', '--json' => true])
                ->doesntExpectOutput('Healing daemon')
                ->doesntExpectOutput('Using daemon device: Thor')
                ->assertExitCode(0);

            expect(DaemonHealSpyCommand::$calls)->toBe([
                ['action' => 'health', 'heal' => true],
            ]);
        });

        it('does not heal when an explicit --device is passed', function (): void {
            DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');

            $searchResult = [
                'uri' => 'spotify:track:phone',
                'name' => 'Phone Song',
                'artist' => 'Artist',
            ];

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
                $mock->shouldReceive('search')->once()->andReturn($searchResult);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->once()->andReturn([
                    ['id' => 'phone-id', 'name' => 'Phone'],
                ]);
                $mock->shouldReceive('play')->once()->with('spotify:track:phone', 'phone-id');
            });

            $this->artisan('play', ['query' => 'test', '--device' => 'Phone'])
                ->expectsOutputToContain('Using device: Phone')
                ->assertExitCode(0);

            expect(DaemonHealSpyCommand::$calls)->toBe([]);
        });

        it('does not heal when there is no daemon conf', function (): void {
            $searchResult = [
                'uri' => 'spotify:track:123',
                'name' => 'Test Song',
                'artist' => 'Test Artist',
            ];

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
                $mock->shouldReceive('search')->once()->andReturn($searchResult);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('play')->once()->with('spotify:track:123', null);
            });

            $this->artisan('play', ['query' => 'test song'])
                ->assertExitCode(0);

            expect(DaemonHealSpyCommand::$calls)->toBe([]);
        });

        it('does not heal when the daemon device is listed and the process is alive', function (): void {
            DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');
            $this->mock(Process::class, function ($mock): void {
                $mock->shouldReceive('isAlive')->once()->andReturn(true);
            });

            $searchResult = [
                'uri' => 'spotify:track:listed',
                'name' => 'Listed Song',
                'artist' => 'Artist',
            ];

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
                $mock->shouldReceive('search')->once()->andReturn($searchResult);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->once()->andReturn([
                    ['id' => 'daemon-id', 'name' => 'Thor'],
                ]);
                $mock->shouldReceive('play')->once()->with('spotify:track:listed', 'daemon-id');
            });

            $this->artisan('play', ['query' => 'test'])
                ->expectsOutputToContain('Using daemon device: Thor')
                ->assertExitCode(0);

            expect(DaemonHealSpyCommand::$calls)->toBe([]);
        });

        it('heals when Connect still lists the speaker but spotifyd is dead', function (): void {
            DaemonHealSpyCommand::writeConf($this->tempDir, 'Thor');
            $this->mock(Process::class, function ($mock): void {
                $mock->shouldReceive('isAlive')->once()->andReturn(false);
            });

            $searchResult = [
                'uri' => 'spotify:track:zombie',
                'name' => 'Zombie Song',
                'artist' => 'Artist',
            ];

            $this->mock(SpotifyAuthManager::class, function ($mock): void {
                $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            });
            $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResult): void {
                $mock->shouldReceive('search')->once()->andReturn($searchResult);
            });
            $this->mock(SpotifyPlayerService::class, function ($mock): void {
                $mock->shouldReceive('getDevices')->twice()->andReturn(
                    [['id' => 'zombie-id', 'name' => 'Thor']],
                    [['id' => 'fresh-id', 'name' => 'Thor']],
                );
                $mock->shouldReceive('play')->once()->with('spotify:track:zombie', 'fresh-id');
            });

            $this->artisan('play', ['query' => 'test'])
                ->expectsOutputToContain('Using daemon device: Thor')
                ->assertExitCode(0);

            expect(DaemonHealSpyCommand::$calls)->toBe([
                ['action' => 'health', 'heal' => true],
            ]);
        });

    });

});
