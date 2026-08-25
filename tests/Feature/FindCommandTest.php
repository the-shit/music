<?php

use App\Services\SpotifyAuthManager;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use Laravel\Prompts\Prompt;

describe('FindCommand', function (): void {

    // Laravel Prompts only engages its test fallbacks (which power expectsSearch /
    // expectsConfirmation) when the app reports the "testing" environment. Laravel
    // Zero doesn't set that by default, so opt in here. The app is rebuilt per test,
    // so this stays local to FindCommand.
    // Laravel Prompts only engages its test fallbacks (which power expectsSearch /
    // expectsConfirmation) when the app reports the "testing" environment, and
    // Laravel Zero doesn't set that by default. Laravel Zero also reuses the
    // application instance across tests, so capture and restore the env (and the
    // global Prompt statics it flips on) to avoid leaking into later test files.
    beforeEach(function (): void {
        $this->originalEnv = $this->app['env'];
        $this->app['env'] = 'testing';
    });

    afterEach(function (): void {
        $this->app['env'] = $this->originalEnv;
        Prompt::interactive(false);

        // Prompt::fallbackWhen() is a latch (`$condition || $shouldFallback`) with no
        // public way back to false, so reset the shared static directly. Otherwise
        // the fallback stays enabled process-wide and breaks later prompt tests.
        $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
        $fallback->setValue(null, false);
    });

    $searchResults = [
        [
            'uri' => 'spotify:track:123',
            'name' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
        ],
    ];

    $expectedOptions = [
        'spotify:track:123' => '🎵 Test Song — Test Artist (Test Album)',
    ];

    it('searches and plays the picked track', function () use ($searchResults, $expectedOptions): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResults): void {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('test song', 'track', 12)
                ->andReturn($searchResults);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('play')->once()->with('spotify:track:123');
            $mock->shouldNotReceive('addToQueue');
        });

        $this->artisan('find')
            ->expectsSearch('🔎 Search Spotify', 'spotify:track:123', 'test song', $expectedOptions)
            ->expectsConfirmation('Add to queue instead of playing now?', 'no')
            ->expectsQuestion('Press enter to continue...', '')
            ->expectsConfirmation('Search for another?', 'no')
            ->expectsOutputToContain('▶️  Playing: spotify:track:123')
            ->assertExitCode(0);
    });

    it('searches and queues the picked track when confirmed', function () use ($searchResults, $expectedOptions): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResults): void {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('test song', 'track', 12)
                ->andReturn($searchResults);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:123');
            $mock->shouldNotReceive('play');
        });

        $this->artisan('find')
            ->expectsSearch('🔎 Search Spotify', 'spotify:track:123', 'test song', $expectedOptions)
            ->expectsConfirmation('Add to queue instead of playing now?', 'yes')
            ->expectsQuestion('Press enter to continue...', '')
            ->expectsConfirmation('Search for another?', 'no')
            ->expectsOutputToContain('➕ Added to queue: spotify:track:123')
            ->assertExitCode(0);
    });

    it('queues directly with the --queue flag without asking', function () use ($searchResults, $expectedOptions): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResults): void {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('test song', 'track', 12)
                ->andReturn($searchResults);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('addToQueue')->once()->with('spotify:track:123');
            $mock->shouldNotReceive('play');
        });

        $this->artisan('find', ['--queue' => true])
            ->expectsSearch('🔎 Search Spotify', 'spotify:track:123', 'test song', $expectedOptions)
            ->expectsQuestion('Press enter to continue...', '')
            ->expectsConfirmation('Search for another?', 'no')
            ->expectsOutputToContain('➕ Added to queue: spotify:track:123')
            ->assertExitCode(0);
    });

    it('hints at "spotify devices" when playback has no device', function () use ($searchResults, $expectedOptions): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });
        $this->mock(SpotifyDiscoveryService::class, function ($mock) use ($searchResults): void {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->andReturn($searchResults);
        });
        $this->mock(SpotifyPlayerService::class, function ($mock): void {
            $mock->shouldReceive('play')
                ->once()
                ->with('spotify:track:123')
                ->andThrow(new Exception('No Spotify devices available. Open Spotify on any device.'));
        });

        $this->artisan('find')
            ->expectsSearch('🔎 Search Spotify', 'spotify:track:123', 'test song', $expectedOptions)
            ->expectsConfirmation('Add to queue instead of playing now?', 'no')
            ->expectsQuestion('Press enter to continue...', '')
            ->expectsConfirmation('Search for another?', 'no')
            ->expectsOutputToContain('No active Spotify device found.')
            ->expectsOutputToContain('spotify devices')
            ->assertExitCode(0);
    });

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('find')
            ->expectsOutputToContain('Spotify is not configured')
            ->assertExitCode(1);
    });

});
