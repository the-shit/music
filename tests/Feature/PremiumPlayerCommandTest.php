<?php

use App\Commands\PremiumPlayerCommand;
use App\Services\SpotifyAuthManager;
use App\Services\SpotifyPlayerService;

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

    /**
     * The queue overlay is now interactive: ⏎ plays the highlighted up-next track
     * (by its own uri) and closes. Drive the handler directly — the php-tui loop
     * can't be run in a non-TTY test, but its play logic is plain and unit-testable.
     */
    it('plays the highlighted up-next track and closes the queue overlay on Enter', function (): void {
        $player = Mockery::mock(SpotifyPlayerService::class);
        $player->shouldReceive('play')->once()->with('spotify:track:abc');

        $command = new PremiumPlayerCommand;
        $method = new ReflectionMethod($command, 'playSelectedQueueTrack');

        $queue = [
            'active' => true,
            'selected' => 1,
            'status' => '',
            'items' => [
                ['uri' => 'spotify:track:zzz'],
                ['uri' => 'spotify:track:abc'],
            ],
        ];
        // invokeArgs binds the by-ref $queue param so we can assert the mutation.
        $args = [$player, &$queue];
        $outcome = $method->invokeArgs($command, $args);

        expect($outcome)->toBe('refresh')
            ->and($queue['active'])->toBeFalse();
    });

    it('keeps the queue overlay open with an inline status when the play fails', function (): void {
        $player = Mockery::mock(SpotifyPlayerService::class);
        $player->shouldReceive('play')->once()->andThrow(new Exception('No active device'));

        $command = new PremiumPlayerCommand;
        $method = new ReflectionMethod($command, 'playSelectedQueueTrack');

        $queue = [
            'active' => true,
            'selected' => 0,
            'status' => '',
            'items' => [['uri' => 'spotify:track:abc']],
        ];
        $args = [$player, &$queue];
        $outcome = $method->invokeArgs($command, $args);

        expect($outcome)->toBe('none')
            ->and($queue['active'])->toBeTrue()
            ->and($queue['status'])->toBe('No active device');
    });

    /**
     * The search palette gained a second action: `a` adds the highlighted result to
     * the queue and KEEPS the palette open (so several can be queued), with a brief
     * inline confirm. Drive the handler directly, like the queue tests above.
     */
    it('adds the highlighted result to the queue and keeps the search palette open', function (): void {
        $player = Mockery::mock(SpotifyPlayerService::class);
        $player->shouldReceive('addToQueue')->once()->with('spotify:track:abc');

        $command = new PremiumPlayerCommand;
        $method = new ReflectionMethod($command, 'queueSelected');

        $search = [
            'active' => true,
            'selected' => 1,
            'status' => '',
            'results' => [
                ['uri' => 'spotify:track:zzz'],
                ['uri' => 'spotify:track:abc'],
            ],
        ];
        $args = [$player, &$search];
        $outcome = $method->invokeArgs($command, $args);

        expect($outcome)->toBe('none')
            ->and($search['active'])->toBeTrue()       // stays open to queue more
            ->and($search['status'])->toBe('+ queued');
    });

    it('surfaces a status when add-to-queue fails and keeps the search palette open', function (): void {
        $player = Mockery::mock(SpotifyPlayerService::class);
        $player->shouldReceive('addToQueue')->once()->andThrow(new Exception('No active device'));

        $command = new PremiumPlayerCommand;
        $method = new ReflectionMethod($command, 'queueSelected');

        $search = [
            'active' => true,
            'selected' => 0,
            'status' => '',
            'results' => [['uri' => 'spotify:track:abc']],
        ];
        $args = [$player, &$search];
        $outcome = $method->invokeArgs($command, $args);

        expect($outcome)->toBe('none')
            ->and($search['active'])->toBeTrue()
            ->and($search['status'])->toBe('No active device');
    });

});
