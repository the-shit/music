<?php

use App\Commands\PremiumPlayerCommand;
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

    /**
     * The loop forces a full repaint whenever the drawn surface changes (panel ↔
     * overlay), which is what stops a closing overlay from leaving residue on the
     * controls strip. currentSurface() is the signal it diffs on, so pin its mapping.
     */
    it('names the active surface so the loop can clear across transitions', function (): void {
        $command = new PremiumPlayerCommand;
        $method = new ReflectionMethod($command, 'currentSurface');
        $surface = fn (array $s, array $q, array $p): string => $method->invoke($command, $s, $q, $p);

        $off = ['active' => false];
        $on = ['active' => true];

        // No overlay open → the now-playing panel.
        expect($surface($off, $off, $off))->toBe('panel');

        // Each overlay reports its own name; search wins the precedence when checked.
        expect($surface($on, $off, $off))->toBe('search');
        expect($surface($off, $on, $off))->toBe('queue');
        expect($surface($off, $off, $on))->toBe('playlist');
    });

});
