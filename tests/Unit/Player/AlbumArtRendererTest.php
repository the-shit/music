<?php

declare(strict_types=1);

use App\Player\AlbumArtRenderer;
use Illuminate\Support\Facades\Http;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\CanvasWidget;
use PhpTui\Tui\Widget\Widget;
use Tests\TestCase;

// WHY: this file uses the Http facade (faked), which needs the Laravel container
// booted. tests/Unit is otherwise pure-PHP, so bind just this file to the app
// TestCase — the decode path is genuinely a unit of the player lane, it merely
// happens to depend on the framework's HTTP client.
uses(TestCase::class);

/**
 * WHY: like the rest of the player lane, the album-art widget is verified by
 * drawing it onto an in-memory php-tui buffer and asserting the resulting cell
 * grid — no live terminal, no network. Http is faked with a tiny bundled PNG so
 * the decode → downscale → half-block paint path runs end-to-end deterministically.
 */
function renderArt(Widget $widget, int $width = 20, int $height = 10): string
{
    $backend = new DummyBackend($width, $height);
    DisplayBuilder::default($backend)->fullscreen()->build()->draw($widget);

    return $backend->toString();
}

function fixtureArtBytes(): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/album-art.png');
}

beforeEach(function (): void {
    // Reset the per-URL static decode cache between tests so fetch-count and
    // failure-path assertions are not contaminated by a sibling test.
    $cache = new ReflectionProperty(AlbumArtRenderer::class, 'cache');
    $cache->setValue(null, []);
});

describe('AlbumArtRenderer', function (): void {

    it('renders a real image URL as a half-block canvas widget', function (): void {
        Http::fake(['*' => Http::response(fixtureArtBytes(), 200)]);

        $widget = (new AlbumArtRenderer)->render('https://i.scdn.co/image/abc', 16, 8);

        // Contract: it returns a Widget, and specifically the Canvas it claims to.
        expect($widget)->toBeInstanceOf(Widget::class)
            ->and($widget)->toBeInstanceOf(CanvasWidget::class);

        // Drawing it must paint coloured half-block glyphs, not blanks — proof the
        // fixture pixels actually reached the grid.
        $out = renderArt($widget);
        $hasBlocks = (bool) preg_match('/[█▀▄]/u', $out);
        expect($hasBlocks)->toBeTrue();
    });

    it('returns the calm placeholder for an empty URL — and never touches the network', function (): void {
        Http::fake();

        $widget = (new AlbumArtRenderer)->render('', 16, 8);

        expect($widget)->toBeInstanceOf(Widget::class);
        expect(renderArt($widget))->toContain('🎵');

        Http::assertNothingSent();
    });

    it('falls back to the placeholder when the fetch fails', function (): void {
        Http::fake(['*' => Http::response('', 404)]);

        $widget = (new AlbumArtRenderer)->render('https://i.scdn.co/image/missing', 16, 8);

        expect($widget)->toBeInstanceOf(Widget::class)
            ->and(renderArt($widget))->toContain('🎵');
    });

    it('falls back to the placeholder when the bytes are not a decodable image', function (): void {
        Http::fake(['*' => Http::response('this is definitely not a png', 200)]);

        $widget = (new AlbumArtRenderer)->render('https://i.scdn.co/image/garbage', 16, 8);

        expect($widget)->toBeInstanceOf(Widget::class)
            ->and(renderArt($widget))->toContain('🎵');
    });

    it('returns the placeholder for a degenerate panel size', function (): void {
        Http::fake(['*' => Http::response(fixtureArtBytes(), 200)]);

        $widget = (new AlbumArtRenderer)->render('https://i.scdn.co/image/abc', 0, 0);

        expect($widget)->toBeInstanceOf(Widget::class)
            ->and(renderArt($widget))->toContain('🎵');
    });

    it('caches decoded art by URL to avoid refetching every frame', function (): void {
        Http::fake(['*' => Http::response(fixtureArtBytes(), 200)]);

        $renderer = new AlbumArtRenderer;
        $renderer->render('https://i.scdn.co/image/abc', 16, 8);
        $renderer->render('https://i.scdn.co/image/abc', 16, 8);
        $renderer->render('https://i.scdn.co/image/abc', 32, 16);

        // Three renders of the same URL → exactly one HTTP fetch.
        Http::assertSentCount(1);
    });

});
