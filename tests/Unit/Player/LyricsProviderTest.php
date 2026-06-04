<?php

declare(strict_types=1);

use App\Player\LyricsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The provider talks through the Http facade, so this file (unlike its pure
// php-tui siblings in Unit/Player) needs the Laravel TestCase to boot the app.
uses(TestCase::class);

/**
 * WHY Http::fake throughout: the provider's only job is turning an lrclib
 * response (or any failure) into displayable lines or null — so every contract
 * branch (match, no match, network failure, instrumental, caching) is pinned
 * without a single real request.
 */
describe('LyricsProvider', function (): void {

    beforeEach(function (): void {
        // Clear the static per-track cache so each test controls its own fetches
        // (same convention as AlbumArtRenderer's decode-cache reset).
        (new ReflectionProperty(LyricsProvider::class, 'cache'))->setValue(null, []);
    });

    it('returns the plain lyrics as lines on a match', function (): void {
        Http::fake([
            'lrclib.net/*' => Http::response([
                'plainLyrics' => "Is this the real life?\nIs this just fantasy?\n\nCaught in a landslide",
                'syncedLyrics' => '[00:01.00] Is this the real life?',
            ]),
        ]);

        $lines = (new LyricsProvider)->lines('Queen', 'Bohemian Rhapsody', 354);

        // Lines split on newlines, interior blanks kept as stanza breaks.
        expect($lines)->toBe([
            'Is this the real life?',
            'Is this just fantasy?',
            '',
            'Caught in a landslide',
        ]);

        // The lookup carries artist/track/duration so lrclib can exact-match.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'lrclib.net/api/get')
            && str_contains($request->url(), 'artist_name=Queen')
            && str_contains($request->url(), 'duration=354'));
    });

    it('returns null when lrclib has no match', function (): void {
        Http::fake([
            'lrclib.net/*' => Http::response(['message' => 'Failed to find specified track'], 404),
        ]);

        expect((new LyricsProvider)->lines('Nobody', 'Unknown Song'))->toBeNull();
    });

    it('returns null on a network failure instead of throwing', function (): void {
        Http::fake([
            'lrclib.net/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        expect((new LyricsProvider)->lines('Queen', 'Bohemian Rhapsody'))->toBeNull();
    });

    it('returns null for instrumental tracks with empty lyrics', function (): void {
        Http::fake([
            'lrclib.net/*' => Http::response(['plainLyrics' => '', 'instrumental' => true]),
        ]);

        expect((new LyricsProvider)->lines('Explosions in the Sky', 'Your Hand in Mine'))->toBeNull();
    });

    it('skips the request entirely for blank artist or title', function (): void {
        Http::fake();

        $provider = new LyricsProvider;

        expect($provider->lines('', 'Song'))->toBeNull()
            ->and($provider->lines('Artist', '  '))->toBeNull();

        Http::assertNothingSent();
    });

    it('caches per track so reopening the overlay never refetches', function (): void {
        Http::fake([
            'lrclib.net/*' => Http::response(['plainLyrics' => 'La la la']),
        ]);

        $provider = new LyricsProvider;
        $first = $provider->lines('Artist', 'Song', 200);
        $second = $provider->lines('Artist', 'Song', 200);

        expect($first)->toBe(['La la la'])
            ->and($second)->toBe(['La la la']);

        // The miss is cached too: a second lookup of a known-missing track is free.
        Http::assertSentCount(1);
    });

    it('caches a miss so a failing track is not re-queried every open', function (): void {
        Http::fake([
            'lrclib.net/*' => Http::response([], 404),
        ]);

        $provider = new LyricsProvider;

        expect($provider->lines('Nobody', 'Nothing'))->toBeNull()
            ->and($provider->lines('Nobody', 'Nothing'))->toBeNull();

        Http::assertSentCount(1);
    });

});
