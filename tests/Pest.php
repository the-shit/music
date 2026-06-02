<?php

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

uses(Tests\TestCase::class)->in('Feature', 'Unit/Agents');

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
