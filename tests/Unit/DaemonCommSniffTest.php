<?php

use App\Commands\DaemonCommand;

/**
 * The pure comm-name check behind daemon health detection. The old exact
 * `$comm === 'spotifyd'` compare was ALWAYS false on macOS — `ps -o comm=`
 * returns the full binary path, and the preferred binary is spotifyd-rodio —
 * so health reported the daemon dead forever and --heal never engaged.
 */
describe('DaemonCommand::isSpotifydComm', function (): void {

    it('accepts spotifyd binaries', function (string $comm): void {
        expect(DaemonCommand::isSpotifydComm($comm))->toBeTrue();
    })->with([
        'spotifyd',
        'spotifyd-rodio',
        '/usr/local/bin/spotifyd',
        '/Users/jordan/.local/bin/spotifyd-rodio',
        "  /Users/jordan/.local/bin/spotifyd-rodio\n", // raw ps output, untrimmed
    ]);

    it('rejects everything else, including recycled PIDs', function (string $comm): void {
        expect(DaemonCommand::isSpotifydComm($comm))->toBeFalse();
    })->with([
        'php',
        '/bin/bash',
        '/usr/local/bin/node',
        '', // process gone — empty ps output
        '/usr/bin/not-spotifyd-at-all/php', // 'spotifyd' in the path but not the binary
    ]);
});
