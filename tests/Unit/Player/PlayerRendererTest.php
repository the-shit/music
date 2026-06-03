<?php

declare(strict_types=1);

use App\Player\AlbumArtRenderer;
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
        'mood' => 'neutral',
        'albumArtUrl' => null,
    ], $overrides);

    return new PlayerViewModel(
        $d['title'], $d['artist'], $d['album'], $d['isPlaying'], $d['progressMs'],
        $d['durationMs'], $d['volume'], $d['shuffle'], $d['repeat'], $d['deviceName'], $d['hasPlayback'],
        $d['mood'], $d['albumArtUrl'],
    );
}

/**
 * Seed AlbumArtRenderer's per-URL decode cache with a fake matrix so hasArt()
 * reports a cover WITHOUT any network/GD — lets us drive the two-column layout
 * deterministically in a pure unit test.
 */
function rendererWithSeededArt(string $mood, string $url): PlayerRenderer
{
    $cache = new ReflectionProperty(AlbumArtRenderer::class, 'cache');
    $cache->setValue(null, [$url => [[[10, 20, 30], [40, 50, 60]], [[70, 80, 90], [100, 110, 120]]]]);

    return new PlayerRenderer(PlayerTheme::forMood($mood), new AlbumArtRenderer);
}

describe('PlayerRenderer', function (): void {

    beforeEach(function (): void {
        // Clear the static decode cache so art-presence is controlled per test.
        (new ReflectionProperty(AlbumArtRenderer::class, 'cache'))->setValue(null, []);
    });

    it('falls back to a clean single-column panel (no art block) when there is no cover', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        // sampleViewModel has albumArtUrl=null → hasArt() is false → single column.
        $out = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel()));

        // The whole point of the fix: NO half-block art glyphs filling dead space,
        // and NO placeholder music-note tile — just the clean info panel.
        expect($out)
            ->not->toContain('▀')
            ->and($out)->not->toContain('▄')
            ->and($out)->toContain('Bohemian Rhapsody')
            ->and($out)->toContain('1:30 / 3:00')
            ->and($out)->toContain('60%');
    });

    it('uses the two-column art layout when a real cover is available', function (): void {
        $url = 'https://i.scdn.co/image/seeded';
        $renderer = rendererWithSeededArt('chill', $url);

        $out = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel(['albumArtUrl' => $url])));

        // Art column now paints half-block glyphs AND the info is still all present.
        $hasArt = (bool) preg_match('/[▀▄█]/u', $out);
        expect($hasArt)->toBeTrue();
        expect($out)
            ->toContain('Bohemian Rhapsody')
            ->toContain('Queen')
            ->toContain('1:30 / 3:00');
    });

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

    it('does not duplicate the music icon in the neutral title', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('neutral'));

        // The title lives on the top border row; neutral must read "🎵 NOW PLAYING"
        // with exactly one music note (regression: it used to trail a second 🎵).
        $topRow = explode("\n", renderPremiumPlayer($renderer->nowPlaying(sampleViewModel())))[0];

        expect($topRow)->toContain('NOW PLAYING')
            ->and(substr_count($topRow, '🎵'))->toBe(1)
            ->and($topRow)->not->toContain('·'); // no trailing badge separator for neutral
    });

    it('shows the mood icon and name in a non-neutral title', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $topRow = explode("\n", renderPremiumPlayer($renderer->nowPlaying(sampleViewModel())))[0];

        expect($topRow)->toContain('😌')
            ->and($topRow)->toContain('NOW PLAYING · chill')
            ->and(substr_count($topRow, '🎵'))->toBe(0); // no stray music note
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
