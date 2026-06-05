<?php

declare(strict_types=1);

use App\Player\PlayerRenderer;
use App\Player\PlayerTheme;
use App\Player\PlayerViewModel;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Widget\Widget;

/**
 * The honest rate-limited empty state: while Spotify is 429-ing the app every
 * poll fails, so "Nothing playing right now" would be a lie — the panel must
 * say we are rate-limited and when polling resumes. Buffer-rendered like the
 * other renderer tests (local helper; the PlayerRendererTest helpers are
 * file-local and load-order dependent, so this file keeps its own).
 */
function renderRateLimitPanel(Widget $widget, int $width = 78, int $height = 12): string
{
    $backend = new DummyBackend($width, $height);
    DisplayBuilder::default($backend)->fullscreen()->build()->draw($widget);

    return $backend->toString();
}

describe('PlayerViewModel rate-limit notice', function (): void {

    it('returns null when not rate-limited', function (): void {
        $vm = PlayerViewModel::fromPlayback(null);

        expect($vm->rateLimitedUntil)->toBeNull();
        expect($vm->rateLimitNotice())->toBeNull();
    });

    it('formats the resume time as ~HH:MM', function (): void {
        $vm = PlayerViewModel::fromPlayback(null);
        $vm->rateLimitedUntil = mktime(14, 30, 0, 6, 4, 2026);

        expect($vm->rateLimitNotice())->toBe('⏳ Spotify is rate-limiting us — resumes ~14:30');
    });
});

describe('PlayerRenderer rate-limited empty state', function (): void {

    it('renders the default empty state when no notice is given', function (): void {
        $output = renderRateLimitPanel((new PlayerRenderer(PlayerTheme::forMood('neutral')))->empty());

        expect($output)->toContain('Nothing playing right now');
        expect($output)->toContain('Press / to search, or start playback on Spotify');
    });

    it('replaces the misleading empty line with the rate-limit notice', function (): void {
        $vm = PlayerViewModel::fromPlayback(null);
        $vm->rateLimitedUntil = mktime(14, 30, 0, 6, 4, 2026);

        $output = renderRateLimitPanel(
            (new PlayerRenderer(PlayerTheme::forMood('neutral')))->empty($vm->rateLimitNotice())
        );

        expect($output)->toContain('Spotify is rate-limiting us — resumes ~14:30');
        expect($output)->not->toContain('Nothing playing right now');
    });
});
