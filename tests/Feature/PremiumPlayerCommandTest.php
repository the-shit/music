<?php

use App\Services\SpotifyAuthManager;

/**
 * WHY these are the only tests: the interactive php-tui loop can't be driven from
 * a non-TTY test process, and it doesn't need to be — the meaningful logic lives
 * in the unit-tested PlayerViewModel/PlayerRenderer. Here we only assert the two
 * entry guards that gate the loop, mirroring PlayerCommand's contract.
 */
describe('PremiumPlayerCommand', function (): void {

    it('requires configuration', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(false);
        });

        $this->artisan('player:premium')
            ->expectsOutputToContain('Spotify is not configured')
            ->expectsOutputToContain('Run "spotify setup" first')
            ->assertExitCode(1);
    });

    it('requires an interactive terminal', function (): void {
        $this->mock(SpotifyAuthManager::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        });

        $this->artisan('player:premium', ['--no-interaction' => true])
            ->expectsOutputToContain('❌ Player requires an interactive terminal')
            ->expectsOutputToContain('💡 Run without piping or in a proper terminal')
            ->assertExitCode(1);
    });

});
