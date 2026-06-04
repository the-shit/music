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

/**
 * Render through the SAME inline viewport the live command uses
 * (PremiumPlayerCommand::VIEWPORT_HEIGHT = 12), not the roomy fullscreen height the
 * other tests use. WHY: an overlay that renders fine at h=16 could still clip its
 * rows/footer to nothing in the cramped 12-row inline viewport, which would read as
 * a no-op when the key is pressed. This pins the overlays' visibility at the real size.
 */
function renderPremiumPlayerInline(Widget $widget, int $width = 78, int $height = 12): string
{
    $backend = new DummyBackend($width, $height);
    DisplayBuilder::default($backend)->inline($height)->build()->draw($widget);

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

    it('renders the search palette with the query, results and a highlighted selection', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $results = [
            ['uri' => 'spotify:track:1', 'name' => 'Daylight', 'artist' => 'Taylor Swift'],
            ['uri' => 'spotify:track:2', 'name' => 'Day N Nite', 'artist' => 'Kid Cudi'],
        ];

        // Second row selected → it must carry the ▶ marker (survives colour-strip).
        $out = renderPremiumPlayer($renderer->searchOverlay('day', $results, 1));

        expect($out)
            ->toContain('Search')                 // palette title
            ->toContain('day')                    // live query echoed
            ->toContain('Daylight')               // result 1
            ->toContain('Day N Nite')             // result 2
            ->toContain('Taylor Swift')           // artist shown
            ->toContain('▶ Day N Nite')           // selection marker on the chosen row
            ->toContain('select')                 // footer hint
            ->toContain('play');
    });

    it('renders the queue overlay with up-next tracks and a highlighted row', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $queue = [
            ['name' => 'Dreams', 'uri' => 'spotify:track:1', 'artists' => [['name' => 'Fleetwood Mac']]],
            ['name' => 'Black', 'uri' => 'spotify:track:2', 'artists' => [['name' => 'Pearl Jam']]],
        ];

        // Second row selected → it carries the ▶ marker (survives colour-strip).
        $out = renderPremiumPlayer($renderer->queueOverlay($queue, 1));

        expect($out)
            ->toContain('Up Next')            // overlay title
            ->toContain('Dreams')             // track 1
            ->toContain('Fleetwood Mac')      // artist 1
            ->toContain('Black')              // track 2
            ->toContain('▶ Black')            // selection marker on the chosen row
            ->toContain('select')             // footer hint
            ->toContain('play')               // queue is now playable (⏎ play)
            ->toContain('close');
    });

    it('surfaces an inline status in the queue overlay without closing it', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $queue = [
            ['name' => 'Dreams', 'uri' => 'spotify:track:1', 'artists' => [['name' => 'Fleetwood Mac']]],
        ];

        // A failed ⏎ play surfaces a status (e.g. no device) but keeps the list up.
        $out = renderPremiumPlayerInline($renderer->queueOverlay($queue, 0, 'No active device'));

        expect($out)
            ->toContain('No active device')
            ->toContain('Dreams')
            ->toContain('play');
    });

    it('shows an empty-queue message when there are no up-next tracks', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('neutral'));

        $out = renderPremiumPlayer($renderer->queueOverlay([], 0));

        expect($out)
            ->toContain('Up Next')
            ->toContain('Queue is empty')
            ->not->toContain('▶');
    });

    it('renders the playlist overlay with names, track counts and a selection', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('hype'));

        $playlists = [
            ['id' => 'p1', 'name' => 'Focus Flow', 'tracks' => ['total' => 42]],
            ['id' => 'p2', 'name' => 'Road Trip', 'tracks' => ['total' => 17]],
        ];

        $out = renderPremiumPlayer($renderer->playlistOverlay($playlists, 0));

        expect($out)
            ->toContain('Playlists')          // overlay title
            ->toContain('Focus Flow')         // playlist 1 name
            ->toContain('42 tracks')          // playlist 1 count
            ->toContain('Road Trip')          // playlist 2 name
            ->toContain('17 tracks')
            ->toContain('▶ Focus Flow')       // selection marker on row 0
            ->toContain('play')               // footer hint (playable, unlike queue)
            ->toContain('cancel');
    });

    it('surfaces an inline status and an empty-state in the playlist overlay', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        // Inline status (e.g. a failed play) is shown without closing the overlay.
        $withStatus = renderPremiumPlayer(
            $renderer->playlistOverlay([['id' => 'p1', 'name' => 'Mix', 'tracks' => ['total' => 5]]], 0, 'No active device')
        );
        expect($withStatus)->toContain('No active device')->toContain('Mix');

        // No playlists → a calm empty message, nothing selectable.
        $empty = renderPremiumPlayer($renderer->playlistOverlay([], 0));
        expect($empty)
            ->toContain('Playlists')
            ->toContain('No playlists found')
            ->not->toContain('▶');
    });

    it('renders the queue overlay with visible items at the real 12-row inline viewport', function (): void {
        // Regression guard: the live player draws into inline(12); an overlay that
        // clipped to nothing here would make `u` look like a no-op. Assert the items,
        // selection marker AND footer all survive at the cramped real size.
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        $queue = [
            ['name' => 'Dreams', 'uri' => 'spotify:track:1', 'artists' => [['name' => 'Fleetwood Mac']]],
            ['name' => 'Black', 'uri' => 'spotify:track:2', 'artists' => [['name' => 'Pearl Jam']]],
        ];

        $out = renderPremiumPlayerInline($renderer->queueOverlay($queue, 1));

        expect($out)
            ->toContain('Up Next')
            ->toContain('Dreams')
            ->toContain('Fleetwood Mac')
            ->toContain('▶ Black')          // selected row still visible
            ->toContain('select')           // footer pinned, not clipped off
            ->toContain('play')
            ->toContain('close');
    });

    it('renders the playlist overlay with visible items at the real 12-row inline viewport', function (): void {
        // Same regression guard for `l`: visible at inline(12), footer not clipped.
        $renderer = new PlayerRenderer(PlayerTheme::forMood('hype'));

        $playlists = [
            ['id' => 'p1', 'name' => 'Focus Flow', 'tracks' => ['total' => 42]],
            ['id' => 'p2', 'name' => 'Road Trip', 'tracks' => ['total' => 17]],
        ];

        $out = renderPremiumPlayerInline($renderer->playlistOverlay($playlists, 0));

        expect($out)
            ->toContain('Playlists')
            ->toContain('▶ Focus Flow')     // selected row visible
            ->toContain('42 tracks')
            ->toContain('Road Trip')
            ->toContain('play')             // footer pinned, not clipped off
            ->toContain('cancel');
    });

    it('shows a visible empty-state in each overlay at the 12-row inline viewport', function (): void {
        // An overlay that rendered BLANK on empty data would also read as a no-op.
        // Pin the empty states as visible at the real size.
        $renderer = new PlayerRenderer(PlayerTheme::forMood('neutral'));

        expect(renderPremiumPlayerInline($renderer->queueOverlay([], 0)))
            ->toContain('Up Next')
            ->toContain('Queue is empty');

        expect(renderPremiumPlayerInline($renderer->playlistOverlay([], 0)))
            ->toContain('Playlists')
            ->toContain('No playlists found');
    });

    it('windows a long queue so the selection stays visible without overflowing', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        // 30 tracks, far more than the 8-row window; select deep into the list.
        $queue = [];
        for ($i = 1; $i <= 30; $i++) {
            $queue[] = ['name' => "Track {$i}", 'uri' => "spotify:track:{$i}", 'artists' => [['name' => "Artist {$i}"]]];
        }

        $out = renderPremiumPlayer($renderer->queueOverlay($queue, 20));

        // The selected row is rendered (window scrolled to it), while rows far
        // outside the window (the very first track) are not.
        expect($out)
            ->toContain('▶ Track 21')   // selected index 20 → "Track 21"
            ->not->toContain('Track 1 ')
            ->and(substr_count($out, '▶'))->toBe(1); // exactly one highlighted row
    });

    it('shows only the input and a hint for an empty search query', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('hype'));

        $out = renderPremiumPlayer($renderer->searchOverlay('', [], 0));

        expect($out)
            ->toContain('Search')
            ->toContain('Type to search')         // empty-query hint, no list
            ->not->toContain('▶');                // nothing selectable yet
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

    it('surfaces live shuffle and repeat state in a separate status line', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('hype'));

        $on = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel(['shuffle' => true, 'repeat' => 'track'])));
        expect($on)
            ->toContain('shuffle on')      // status line, not the key legend
            ->toContain('repeat Track')
            ->toContain('hype');           // mood-aware

        $off = renderPremiumPlayer($renderer->nowPlaying(sampleViewModel(['shuffle' => false, 'repeat' => 'off'])));
        expect($off)
            ->toContain('shuffle off')
            ->toContain('repeat Off');
    });

    it('renders a readable key legend with no state and no wide emoji, at the inline viewport', function (): void {
        $renderer = new PlayerRenderer(PlayerTheme::forMood('chill'));

        // Real 12-row inline viewport — the size the live player draws into.
        $out = renderPremiumPlayerInline($renderer->nowPlaying(sampleViewModel()));

        // Every binding is present as a readable `key action` pair…
        expect($out)
            ->toContain('play/pause')
            ->toContain('next')
            ->toContain('prev')
            ->toContain('search')
            ->toContain('queue')
            ->toContain('playlists')
            ->toContain('shuffle')
            ->toContain('repeat')
            ->toContain('quit');

        // …and the variation-selector emoji that made the old strip read wide are
        // GONE from the now-playing surface (legend + status are plain text).
        expect($out)
            ->not->toContain('▶️')
            ->and($out)->not->toContain('⏸️')
            ->and($out)->not->toContain('⏭️')
            ->and($out)->not->toContain('⏮️')
            ->and($out)->not->toContain('🔀')
            ->and($out)->not->toContain('🔁');

        // The live state lives on its own status line, separate from the legend.
        expect($out)->toContain('shuffle on')->toContain('repeat All');
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
