<?php

declare(strict_types=1);

use App\Services\Daemon\AudioBackend;

describe('daemon audio backend', function (): void {
    it('writes pulseaudio on linux even when the binary advertises rodio', function (): void {
        expect(AudioBackend::resolve('Linux', 'backend = "rodio"', 'rodio'))->toBe('pulseaudio');
    });

    it('does not rewrite an existing pulseaudio backend back to rodio', function (): void {
        expect(AudioBackend::resolve('Darwin', 'backend = "pulseaudio"', 'rodio'))->toBe('pulseaudio');
    });

    it('keeps rodio on macOS when that is what the binary offers', function (): void {
        expect(AudioBackend::resolve('Darwin', '', 'backends: rodio'))->toBe('rodio');
        expect(AudioBackend::resolve('Darwin', '', 'portaudio'))->toBe('portaudio');
    });

    it('never writes a hardware pin and leaves the linux sink unpinned', function (): void {
        expect(AudioBackend::deviceLine('Linux', 'hw:0,0'))->toBeNull();
        expect(AudioBackend::deviceLine('Linux', 'alsa_output.usb'))->toBeNull();
        expect(AudioBackend::deviceLine('Darwin', 'hw:0,0'))->toBeNull();
        expect(AudioBackend::deviceLine('Darwin', 'Wave Link Stream'))->toBe('device = "Wave Link Stream"');
    });

    it('notices librespot device-not-available panics', function (): void {
        expect(AudioBackend::logShowsDeviceNotAvailable('DeviceNotAvailable("hw:0,0")'))->toBeTrue();
        expect(AudioBackend::logShowsDeviceNotAvailable('context is not available'))->toBeFalse();
    });
});
