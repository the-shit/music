<?php

use function Laravel\Prompts\spin;

/*
| Regression guard for the intermittent full-suite hang / exit-129.
|
| Laravel Prompts' Spinner::spin() forks a spinner child whenever pcntl_fork()
| and posix_kill() both exist, never reaps it, and on enough accumulated
| zombies its destructor broadcasts posix_kill(-1, SIGHUP) — killing the test
| runner mid-suite. spin() ignores Prompt::interactive()/fallbackWhen(), so the
| only lever is making pcntl_fork() unavailable. The suite is therefore launched
| with `-d disable_functions=pcntl_fork` (see composer.json). These tests fail
| loudly if that flag ever goes missing.
*/

it('runs the suite with pcntl_fork disabled so spin() cannot fork', function (): void {
    expect(function_exists('pcntl_fork'))->toBeFalse(
        'Run the suite with `composer test` (which sets -d disable_functions=pcntl_fork). '
        .'Without it Laravel Prompts spin() forks an unreaped spinner child whose destructor '
        .'eventually broadcasts SIGHUP and kills the runner (exit 129).'
    );
});

it('keeps posix_kill available for the daemon tests', function (): void {
    // Only pcntl_fork is disabled — posix_kill must remain callable.
    expect(function_exists('posix_kill'))->toBeTrue();
});

it('executes spin() callbacks synchronously without spawning a child', function (): void {
    $children = static function (): array {
        $out = trim((string) shell_exec('pgrep -P '.getmypid().' 2>/dev/null'));

        return $out === '' ? [] : explode("\n", $out);
    };

    // Baseline captured the same way (so shell_exec's own transient helper is
    // accounted for identically) — a forked spinner child shows up as a NEW pid.
    $baseline = $children();
    $duringCallback = $baseline;

    $result = spin(function () use ($children, &$duringCallback) {
        $duringCallback = $children();

        return 'callback-ran';
    }, 'working');

    expect($result)->toBe('callback-ran')
        ->and(array_diff($duringCallback, $baseline))->toBe([]);
});
