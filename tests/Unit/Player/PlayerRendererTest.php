<?php

declare(strict_types=1);

use App\Player\PlayerRenderer;
use App\Player\PlayerTheme;
use App\Player\PlayerViewModel;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Widget\Widget;

/**
 * WHY: the renderer is verified by drawing its widget tree onto an in-memory
 * php-tui buffer (DummyBackend) and asserting the resulting text grid — no live
 * terminal, no eyeballing. This is the payoff of separating data/theme/render:
 * the GUI is unit-testable.
 */
function renderPremiumPlayer(Widget $widget, int $width = 78, int $height = 16): string
{
    $backend = new DummyBackend($width, $height);
    DisplayBuilder::default($backend)->fullscreen()->build()->draw($widget);

    return $backend->toString();
}

function sampleViewModel(array $overrides = []): PlayerViewModel
{
    $d = array_merge([
        'title' => 'Bohemian Rhapsody',
        'artist' => 'Queen',
        'album' => 'A Night at the Opera',
        'isPlaying' => true,
        'progressMs' => 90_000,
        'durationMs' => 180_000,
        'volume' => 60,
        'shuffle' => true,
        'repeat' => 'context',
        'deviceName' => 'Living Room',
        'hasPlayback' => true,
    ], $overrides);

    return new PlayerViewModel(
        $d['title'], $d['artist'], $d['album'], $d['isPlaying'], $d['progressMs'],
        $d['durationMs'], $d['volume'], $d['shuffle'], $d['repeat'], $d['deviceName'], $d['hasPlayback'],
    );
}

describe('PlayerRenderer', function (): void {

    it('composes the now-playing panel with track, artist, progress and mood badge', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $out = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel()));

        expect($out)
            ->toContain('NOW PLAYING')
            ->toContain('chill')               // mood badge in the heading
            ->toContain('Bohemian Rhapsody')   // track title
            ->toContain('Queen')               // artist
            ->toContain('A Night at the Opera')// album
            ->toContain('1:30 / 3:00')         // progress gauge label
            ->toContain('60%');                // volume gauge label
    });

    it('surfaces live shuffle and repeat state in the controls hint', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('hype'));

        $on = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel(['shuffle' => true, 'repeat' => 'track'])));
        expect($on)->toContain('on')->toContain('Track')->toContain('hype'); // mood-aware

        $off = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel(['shuffle' => false, 'repeat' => 'off'])));
        expect($off)->toContain('off')->toContain('Off');
    });

    it('renders a calm empty state', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('neutral'));

        $out = renderPremiumPlayer($renderer->empty());

        expect($out)
            ->toContain('NOW PLAYING')
            ->toContain('Nothing playing right now')
            ->toContain('Press / to search');
    });

    it('truncates an overlong title with an ellipsis', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $out = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel([
            'title' => str_repeat('VeryLongSongTitle ', 8),
        ])));

        expect($out)->toContain('…');
    });

    it('handles zero duration and null volume without error', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $out = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel([
            'isPlaying' => false,
            'progressMs' => 0,
            'durationMs' => 0,
            'volume' => null,
        ])));

        expect($out)
            ->toContain('0:00 / 0:00')
            ->toContain('n/a');
    });

});
