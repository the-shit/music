<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;
use Illuminate\Support\Facades\Artisan;

describe('QueueShowCommand', function (): void {

    it('requires Spotify to be configured', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup"')
            ->assertExitCode(1);
    });

    it('shows empty queue message when queue is empty', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Queue is empty')
            ->assertExitCode(0);
    });

    it('shows the currently playing track when present', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'name' => 'Stairway to Heaven',
                    'artists' => [['name' => 'Led Zeppelin']],
                ],
                'queue' => [
                    ['name' => 'Black Dog', 'artists' => [['name' => 'Led Zeppelin']]],
                ],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Now: Stairway to Heaven by Led Zeppelin')
            ->expectsOutputToContain('Up Next')
            ->assertExitCode(0);
    });

    it('lists queued tracks with numbers', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['name' => 'First Track', 'artists' => [['name' => 'Artist A']]],
                    ['name' => 'Second Track', 'artists' => [['name' => 'Artist B']]],
                    ['name' => 'Third Track', 'artists' => [['name' => 'Artist C']]],
                ],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('First Track')
            ->expectsOutputToContain('Second Track')
            ->expectsOutputToContain('Third Track')
            ->assertExitCode(0);
    });

    it('uses Unknown for tracks with missing artist', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['name' => 'Mystery', 'artists' => []],
                ],
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Unknown')
            ->assertExitCode(0);
    });

    it('shows total track count', function (): void {
        $queue = [];
        for ($i = 1; $i <= 5; $i++) {
            $queue[] = ['name' => "Track {$i}", 'artists' => [['name' => 'Artist']]];
        }

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($queue): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => $queue,
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('5 tracks queued')
            ->assertExitCode(0);
    });

    it('indicates when more than 20 tracks exist', function (): void {
        $queue = [];
        for ($i = 1; $i <= 25; $i++) {
            $queue[] = ['name' => "Track {$i}", 'artists' => [['name' => 'Artist']]];
        }

        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock) use ($queue): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => $queue,
            ]);
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('5 more')
            ->assertExitCode(0);
    });

    it('outputs JSON when --json flag is provided', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'name' => 'Now Song',
                    'artists' => [['name' => 'Now Artist']],
                ],
                'queue' => [
                    ['name' => 'Next Song', 'artists' => [['name' => 'Next Artist']]],
                ],
            ]);
        });

        Artisan::call('queue:show', ['--json' => true]);
        $output = Artisan::output();

        expect($output)->toContain('"currently_playing"')
            ->toContain('Now Song')
            ->toContain('"total":1');
    });

    it('outputs JSON with null currently_playing when nothing is playing', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [],
            ]);
        });

        $this->artisan('queue:show', ['--json' => true])
            ->expectsOutputToContain('"currently_playing":null')
            ->assertExitCode(0);
    });

    it('handles API errors gracefully', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('getQueue')
                ->once()
                ->andThrow(new Exception('Network timeout'));
        });

        $this->artisan('queue:show')
            ->expectsOutputToContain('Failed to get queue: Network timeout')
            ->assertExitCode(1);
    });

    it('has the correct command name', function (): void {
        $command = $this->app->make(\App\Commands\QueueShowCommand::class);
        expect($command->getName())->toBe('queue:show');
    });

    it('has a description', function (): void {
        $command = $this->app->make(\App\Commands\QueueShowCommand::class);
        expect($command->getDescription())->not->toBeEmpty();
    });

});
