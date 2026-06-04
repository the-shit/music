<?php

declare(strict_types=1);

use App\Player\PlayerTheme;

/**
 * WHY: PlayerTheme::nextOverride() drives the player's `t` key — the manual theme
 * picker that overrides the genre-auto-detected mood (which often lands on
 * 'neutral'). These tests pin the cycle order (null = Auto first, then every mood
 * the moodLabel/moodColor vocabulary renders), the wrap-around back to Auto, and
 * the reset-to-Auto behaviour for unrecognised values, so the keybinding can stay
 * a dumb "advance one step" in the command loop.
 */
it('starts the cycle at Auto and steps to the first mood', function (): void {
    expect(PlayerTheme::nextOverride(null))->toBe('chill');
});

it('advances one mood per step in the documented order', function (?string $current, ?string $next): void {
    expect(PlayerTheme::nextOverride($current))->toBe($next);
})->with([
    'chill → flow' => ['chill', 'flow'],
    'flow → focus' => ['flow', 'focus'],
    'focus → hype' => ['focus', 'hype'],
    'hype → party' => ['hype', 'party'],
    'party → upbeat' => ['party', 'upbeat'],
    'upbeat → melancholy' => ['upbeat', 'melancholy'],
    'melancholy → ambient' => ['melancholy', 'ambient'],
    'ambient → workout' => ['ambient', 'workout'],
    'workout → sleep' => ['workout', 'sleep'],
]);

it('wraps from the last mood back to Auto', function (): void {
    expect(PlayerTheme::nextOverride('sleep'))->toBeNull();
});

it('walks the full ring back to Auto in exactly one lap', function (): void {
    $override = null;

    foreach (range(1, count(PlayerTheme::MOOD_CYCLE)) as $step) {
        $override = PlayerTheme::nextOverride($override);
    }

    expect($override)->toBeNull();
});

it('resets unrecognised values to Auto instead of throwing', function (): void {
    // 'neutral' is deliberately NOT in the cycle (it IS the auto fallback), and
    // arbitrary junk must land safely too — the override always recovers to Auto.
    expect(PlayerTheme::nextOverride('neutral'))->toBeNull()
        ->and(PlayerTheme::nextOverride('bogus'))->toBeNull();
});

it('cycles only moods the theme vocabulary knows how to badge', function (): void {
    foreach (PlayerTheme::MOOD_CYCLE as $mood) {
        if ($mood === null) {
            continue; // Auto — no badge of its own; the detected mood shows instead
        }

        // moodLabel() gives each KNOWN mood its own emoji and falls back to a
        // generic 🎵 prefix otherwise — so a non-🎵 badge proves the mapping exists.
        expect(PlayerTheme::forMood($mood)->moodBadge())->not->toStartWith('🎵');
    }
});
