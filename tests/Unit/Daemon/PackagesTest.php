<?php

declare(strict_types=1);

use App\Services\Daemon\Packages;

describe('daemon packages', function (): void {
    it('uses brew on macOS', function (): void {
        expect(Packages::installCommand('Darwin', ['spotifyd', 'sox'], false))
            ->toBe('brew install spotifyd sox');
    });

    it('uses omarchy pkg add when omarchy is present', function (): void {
        $command = Packages::installCommand('Linux', ['spotifyd', 'sox'], true);

        expect($command)->toBe('omarchy pkg add spotifyd sox');
        expect($command)->not->toContain('apt');
    });

    it('uses pacman on linux without omarchy', function (): void {
        $command = Packages::installCommand('Linux', ['spotifyd'], false);

        expect($command)->toBe('sudo pacman -S --needed --noconfirm spotifyd');
        expect($command)->not->toContain('apt');
    });

    it('refuses an unknown operating system', function (): void {
        expect(Packages::installCommand('Windows', ['spotifyd'], false))->toBeNull();
    });
});
