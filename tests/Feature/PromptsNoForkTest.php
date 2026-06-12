<?php

use function Laravel\Prompts\spin;

/*
| Regression guard for the intermittent full-suite hang / exit-129.
|
| Laravel Prompts' Spinner::spin() forks a spinner child whenever pcntl_fork()
| and posix_kill() both exist, never reaps it, and once enough zombies pile up
| its destructor broadcasts posix_kill(-1, SIGHUP) — killing the test runner
| mid-suite. The fix (see tests/Pest.php) launches the suite with
| `-d disable_functions=pcntl_fork` (composer test + smoke CI) so spin() takes
| its synchronous renderStatically() path and never forks. Whatever the runner,
| spin() must keep running its callback inline and returning its value — that
| is what these tests pin down, without racing on live PIDs (which catches
| unrelated CI processes and is flaky).
*/

it('runs spin() callbacks inline and returns their value', function (): void {
    // If spin() ran the callback in a forked child, this counter would be
    // incremented in that child and the parent would still see 0. Seeing the
    // mutation here proves the callback ran synchronously in this process.
    $ranInThisProcess = 0;

    $result = spin(function () use (&$ranInThisProcess): string {
        $ranInThisProcess++;

        return 'callback-ran';
    }, 'working');

    expect($result)->toBe('callback-ran')
        ->and($ranInThisProcess)->toBe(1);
});

it('keeps posix_kill available (only pcntl_fork is disabled)', function (): void {
    // The fix disables pcntl_fork but never posix_kill, so the daemon/login
    // tests that assert on posix_kill($pid, 0) keep working.
    expect(function_exists('posix_kill'))->toBeTrue();
});
