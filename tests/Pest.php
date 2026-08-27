<?php

use Laravel\Prompts\Prompt;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| mbstring Polyfill
|--------------------------------------------------------------------------
|
| The mb_strimwidth function is not included in symfony/polyfill-mbstring.
| This provides a basic implementation for testing environments without mbstring.
|
*/

if (! function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $string, int $start, int $width, string $trim_marker = '', ?string $encoding = null): string
    {
        $encoding = $encoding ?? 'UTF-8';

        // Get substring starting at $start
        $str = mb_substr($string, $start, null, $encoding);

        // If string width is within limit, return as is
        if (mb_strwidth($str, $encoding) <= $width) {
            return $str;
        }

        // Calculate width available for content (minus trim marker width)
        $markerWidth = mb_strwidth($trim_marker, $encoding);
        $availableWidth = $width - $markerWidth;

        if ($availableWidth < 0) {
            return $trim_marker;
        }

        // Truncate character by character until we fit
        $result = '';
        $currentWidth = 0;
        $length = mb_strlen($str, $encoding);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($str, $i, 1, $encoding);
            $charWidth = mb_strwidth($char, $encoding);

            if ($currentWidth + $charWidth > $availableWidth) {
                break;
            }

            $result .= $char;
            $currentWidth += $charWidth;
        }

        return $result.$trim_marker;
    }
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature', 'Unit/Agents', 'Unit/Daemon');

/*
| Terminal & process safety net for the whole suite.
|
| Laravel Prompts' spin() forks a spinner child and, in its destructor, kills it
| with posix_kill(pid, SIGHUP) but NEVER reaps it. Across a full suite these
| zombies accumulate until fork() fails — at which point the destructor runs
| posix_kill(-1, SIGHUP), broadcasting SIGHUP to the whole process group and
| killing the test runner mid-suite (exit 129) at a non-deterministic point.
| Reaping the children after every test keeps the process table clean.
|
| The stty restore is belt-and-braces in case a command (e.g. the php-tui
| player) ever leaves the terminal in raw mode / the alternate screen.
*/
uses()->afterEach(function (): void {
    if (function_exists('pcntl_waitpid')) {
        while (@pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // reap any finished spinner-fork children
        }
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        @exec('stty sane 2>/dev/null');
    }
})->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Laravel Prompts — fall back to Symfony questions in every test
|--------------------------------------------------------------------------
|
| Without the fallback, select()/text() render to the real terminal: under
| a TTY they block on keyboard input forever (the suite used to hang
| deterministically at PlayerCommandTest when run in a real terminal), and
| without one they only terminate by throwing on EOF. With the fallback,
| prompts route through Symfony questions, expectsQuestion() can drive
| them, and the suite behaves identically in CI, pipes, and terminals.
| fallbackWhen() ORs into sticky static state, so set it suite-wide here
| rather than per-file where it would be order-dependent.
*/
uses()->beforeEach(function (): void {
    Prompt::fallbackWhen(true);
})->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Laravel Prompts spin() — deterministic full suite (no SIGHUP hang)
|--------------------------------------------------------------------------
|
| Laravel\Prompts\Spinner::spin() forks a spinner child whenever both
| pcntl_fork() and posix_kill() exist, kills that child from its destructor
| with posix_kill($pid, SIGHUP), but never reaps it. Across a full suite the
| zombies accumulate until fork() itself fails and returns -1 — at which point
| the destructor runs posix_kill(-1, SIGHUP), broadcasting SIGHUP to the whole
| process group and killing the test runner mid-suite (the intermittent
| exit-129 hang).
|
| The fix is to stop spin() forking. spin() bypasses
| Prompt::interactive()/fallbackWhen() entirely — it branches only on
| function_exists('pcntl_fork') — so the only switch is the function's
| availability. disable_functions is PHP_INI_SYSTEM and cannot be flipped from
| PHP at runtime (no ini_set, no phpunit <ini>, and the prompts helper is
| already autoloaded before this file runs, so it can't be shadowed either).
| The suite is therefore launched with `-d disable_functions=pcntl_fork`:
|
|   - composer.json "test" / "test:coverage" scripts
|   - .github/workflows/smoke.yml test step
|
| With pcntl_fork unavailable spin() takes its synchronous renderStatically()
| path: the callback runs inline, nothing forks, and the fatal SIGHUP is never
| sent. Only pcntl_fork is disabled — posix_kill stays available for the daemon
| tests that assert on it. (Reaping spinner children after each test was tried
| and does NOT prevent the hang: a heavy test outpaces an afterEach hook, and
| the Spinner restores pcntl_async_signals to off after every call, defeating a
| SIGCHLD auto-reaper. Removing the fork is the only reliable fix.)
|
| Always run the suite via `composer test`. tests/Feature/PromptsNoForkTest.php
| pins the behaviour that matters: spin() runs its callback inline and returns
| its value.
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}
