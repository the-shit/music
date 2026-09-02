<?php

declare(strict_types=1);

use App\Support\DeviceMatcher;

it('matches a device by name substring', function (): void {
    $match = DeviceMatcher::find([
        ['id' => 'thor-id', 'name' => 'Thor Speaker'],
        ['id' => 'phone-id', 'name' => 'Phone'],
    ], 'Thor');

    expect($match)->toBe(['id' => 'thor-id', 'name' => 'Thor Speaker']);
});

it('matches a device by exact id', function (): void {
    $match = DeviceMatcher::find([
        ['id' => 'abc123', 'name' => 'Kitchen'],
    ], 'abc123');

    expect($match)->toBe(['id' => 'abc123', 'name' => 'Kitchen']);
});

it('returns null when nothing matches', function (): void {
    expect(DeviceMatcher::find([
        ['id' => 'phone-id', 'name' => 'Phone'],
    ], 'Thor'))->toBeNull();
});
