<?php

declare(strict_types=1);

namespace App\Player;

use GdImage;
use Illuminate\Support\Facades\Http;
use PhpTui\Tui\Canvas\CanvasContext;
use PhpTui\Tui\Canvas\Marker;
use PhpTui\Tui\Canvas\Painter;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Extension\Core\Shape\ClosureShape;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\CanvasWidget;
use PhpTui\Tui\Extension\Core\Widget\Chart\AxisBounds;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use Throwable;

/**
 * Renders Spotify album art as true-colour terminal art via php-tui's Canvas.
 *
 * WHY this is an isolated, standalone lane: turning a remote JPEG into coloured
 * terminal cells touches three risky concerns — network I/O, GD decoding, and
 * pixel→cell mapping — none of which belong in the player loop, the view model,
 * or the now-playing renderer. Keeping it behind a single render() seam means
 * the integrator can drop art into a two-column layout without inheriting any
 * of that risk, and every failure mode degrades to a calm placeholder instead
 * of throwing inside the draw loop.
 *
 * Technique: php-tui's {@see Marker::HalfBlock} grid packs two vertical pixels
 * into one cell using ▀/▄/█ with independent fg/bg colours, doubling vertical
 * resolution so a roughly-square panel shows roughly-square art. We paint each
 * scaled pixel straight onto the grid through a {@see ClosureShape}; php-tui
 * owns the cell composition, we only supply colours.
 */
final class AlbumArtRenderer
{
    /**
     * Longest-edge cap for the cached source matrix. Album art arrives at
     * 300–640px; we never need more than a terminal panel's worth of pixels, so
     * we downscale once on decode to bound both memory and per-frame rescale cost.
     */
    private const SOURCE_CAP = 64;

    /**
     * Decoded art cache, keyed by URL. Values: a pixel matrix (success) or null
     * (empty URL, no GD, fetch/decode failure). array_key_exists distinguishes
     * "never tried" from "tried and failed" so a broken/again-missing URL is not
     * refetched every single frame of the ~1s player poll.
     *
     * @var array<string, list<list<array{int, int, int}>>|null>
     */
    private static array $cache = [];

    /**
     * Turn an album-art URL into a renderable Canvas widget at the given cell size.
     *
     * Never throws: an empty/invalid URL, a missing GD extension, or any
     * fetch/decode failure all resolve to placeholder() so the caller can render
     * unconditionally.
     */
    public function render(string $imageUrl, int $cols, int $rows): Widget
    {
        $matrix = $imageUrl === '' ? null : $this->decode($imageUrl);

        // Nothing to draw, or a degenerate panel → calm placeholder, never art.
        if ($matrix === null || $cols <= 0 || $rows <= 0) {
            return $this->placeholder();
        }

        // The painter closure runs at draw time, when the real grid resolution is
        // known — so the art is rescaled to whatever area the layout actually
        // grants it (fully responsive to terminal resize), not to the cols/rows
        // hint. cols/rows only seed the canvas bounds.
        return CanvasWidget::default()
            ->marker(Marker::HalfBlock)
            ->backgroundColor(AnsiColor::Reset)
            ->xBounds(AxisBounds::new(0, max(1, $cols)))
            ->yBounds(AxisBounds::new(0, max(1, $rows)))
            ->paint(function (CanvasContext $ctx) use ($matrix): void {
                // HalfBlock resolution() = (cols, rows*2): one extra pixel row per
                // cell. This is the true pixel canvas we scale the art into.
                $resolution = $ctx->grid->resolution();
                $pixelWidth = $resolution->width;
                $pixelHeight = $resolution->height;

                if ($pixelWidth <= 0 || $pixelHeight <= 0) {
                    return;
                }

                $pixels = $this->rescale($matrix, $pixelWidth, $pixelHeight);

                // Paint pixels directly onto the grid via ClosureShape — the only
                // php-tui seam that exposes raw (Position, Color) painting without
                // a bespoke registered ShapePainter.
                $ctx->draw(new ClosureShape(function (Painter $painter) use ($pixels, $pixelWidth, $pixelHeight): void {
                    for ($y = 0; $y < $pixelHeight; $y++) {
                        for ($x = 0; $x < $pixelWidth; $x++) {
                            [$r, $g, $b] = $pixels[$y][$x];
                            $painter->paint(Position::at($x, $y), RgbColor::fromRgb($r, $g, $b));
                        }
                    }
                }));
            });
    }

    /**
     * Whether a real, successfully-decoded cover is available for this URL.
     *
     * WHY: lets the caller (PlayerRenderer) decide LAYOUT before drawing — show the
     * two-column art|info panel only when there is genuine art, and fall back to the
     * clean single-column panel otherwise, rather than rendering a dead placeholder
     * block. Cheap and side-effect-free for repeated calls: it shares decode()'s
     * per-URL cache, so asking here and then render()ing costs one fetch, not two.
     */
    public function hasArt(?string $imageUrl): bool
    {
        return $imageUrl !== null && $imageUrl !== '' && $this->decode($imageUrl) !== null;
    }

    /**
     * Fetch + decode the art into a capped RGB matrix, caching the result by URL.
     *
     * @return list<list<array{int, int, int}>>|null null on any failure
     */
    private function decode(string $url): ?array
    {
        if (array_key_exists($url, self::$cache)) {
            return self::$cache[$url];
        }

        // GD is the decoder; without it we can't turn bytes into pixels, so we
        // degrade rather than fatal. Cached so the check is paid once per URL.
        if (! $this->gdAvailable()) {
            return self::$cache[$url] = null;
        }

        try {
            // Short timeout: art is cosmetic and must never stall the draw loop.
            $response = Http::timeout(5)->get($url);

            if (! $response->successful()) {
                return self::$cache[$url] = null;
            }

            $bytes = $response->body();
        } catch (Throwable) {
            // DNS, TLS, connection, timeout — all collapse to "no art".
            return self::$cache[$url] = null;
        }

        if ($bytes === '') {
            return self::$cache[$url] = null;
        }

        // @-suppressed: imagecreatefromstring emits a warning on garbage bytes;
        // we treat its `false` return as the failure signal instead.
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return self::$cache[$url] = null;
        }

        $matrix = $this->toMatrix($image);
        imagedestroy($image);

        return self::$cache[$url] = $matrix;
    }

    /**
     * Read a GD image into a row-major RGB matrix, downscaling its longest edge to
     * SOURCE_CAP first so the cache entry stays small regardless of source size.
     *
     * @return list<list<array{int, int, int}>>
     */
    private function toMatrix(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Downscale once up front; imagescale keeps aspect when height is omitted.
        if (max($width, $height) > self::SOURCE_CAP) {
            $targetWidth = $width >= $height
                ? self::SOURCE_CAP
                : (int) max(1, round($width * self::SOURCE_CAP / $height));

            $scaled = imagescale($image, $targetWidth);

            if ($scaled instanceof GdImage) {
                $image = $scaled;
                $width = imagesx($image);
                $height = imagesy($image);
            }
        }

        $matrix = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                // imagecolorsforindex works for both palette and true-colour
                // images, unlike unpacking the raw int from imagecolorat.
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $row[] = [$rgb['red'], $rgb['green'], $rgb['blue']];
            }
            $matrix[] = $row;
        }

        return $matrix;
    }

    /**
     * Nearest-neighbour rescale of an RGB matrix to a target pixel grid. Pure PHP
     * (no GD) so the hot per-frame path holds no native image handles and stays
     * trivially testable. Nearest-neighbour is deliberate: at album-art scale it
     * is sharp, fast, and free of edge artefacts.
     *
     * @param  list<list<array{int, int, int}>>  $source
     * @return list<list<array{int, int, int}>>
     */
    private function rescale(array $source, int $targetWidth, int $targetHeight): array
    {
        $sourceHeight = count($source);
        $sourceWidth = $sourceHeight > 0 ? count($source[0]) : 0;

        if ($sourceWidth === 0 || $sourceHeight === 0 || $targetWidth <= 0 || $targetHeight <= 0) {
            return [];
        }

        $out = [];
        for ($y = 0; $y < $targetHeight; $y++) {
            $sy = (int) floor($y * $sourceHeight / $targetHeight);
            $row = [];
            for ($x = 0; $x < $targetWidth; $x++) {
                $sx = (int) floor($x * $sourceWidth / $targetWidth);
                $row[] = $source[$sy][$sx];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The calm fallback: a centred music note on a dim, borderless block. Reads as
     * a deliberate "no art" tile, not an error — so the player surface stays whole
     * whether the track has no image, GD is absent, or the fetch failed.
     */
    private function placeholder(): Widget
    {
        $dim = Style::default()->fg(AnsiColor::DarkGray);

        $body = ParagraphWidget::fromLines(
            Line::fromString(''),
            Line::fromString('🎵'),
        )
            ->style($dim)
            ->alignment(HorizontalAlignment::Center);

        return BlockWidget::default()
            ->borders(Borders::NONE)
            ->style($dim)
            ->widget($body);
    }

    private function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }
}
