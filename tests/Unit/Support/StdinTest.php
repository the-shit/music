<?php

declare(strict_types=1);

use App\Support\Stdin;

it('never prompts under --json', function (bool $inputInteractive, bool $stdinIsTty, bool $namedTarget): void {
    expect(Stdin::allowsPrompt(true, $inputInteractive, $stdinIsTty, $namedTarget))->toBeFalse();
})->with([
    'tty and interactive' => [true, true, false],
    'pipe and interactive' => [true, false, false],
    'named target on a tty' => [true, true, true],
]);

it('does not prompt when stdin is not a tty', function (): void {
    expect(Stdin::allowsPrompt(false, true, false, false))->toBeFalse();
});

it('does not prompt when the console input is non-interactive', function (): void {
    expect(Stdin::allowsPrompt(false, false, true, false))->toBeFalse();
});

it('does not prompt when a device name or id was given', function (): void {
    expect(Stdin::allowsPrompt(false, true, true, true))->toBeFalse();
});

it('prompts only for an interactive tty with no named target', function (): void {
    expect(Stdin::allowsPrompt(false, true, true, false))->toBeTrue();
});
