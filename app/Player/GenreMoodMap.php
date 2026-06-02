<?php

declare(strict_types=1);

namespace App\Player;

/**
 * Maps Spotify artist genres onto the player's mood vocabulary.
 *
 * WHY: The premium player tints itself by "mood" (see RendersPlayback::moodColor/
 * moodLabel — chill/flow/focus/hype/party/upbeat/melancholy/ambient/workout/
 * sleep, else neutral). The original mood source was Spotify's /audio-features
 * endpoint, but that now 403s for most apps post-2024. Artist genres (from the
 * NON-deprecated GET /artists/{id}) are the reliable replacement signal, so this
 * class turns a genre string — or the artist's genre array — into ONE mood.
 *
 * Pure: no I/O, no Laravel, no php-tui. Just genre text in, mood string out, so
 * the whole thing is trivially unit-testable and the rendering lanes stay clean.
 */
final class GenreMoodMap
{
    /**
     * Ordered substring → mood rules.
     *
     * WHY ordered: genres overlap ("dance pop", "ambient techno"), so the FIRST
     * matching rule wins and order encodes priority — the more specific / more
     * mood-defining cues sit above the generic ones. Each needle is matched as a
     * lowercase substring against the genre text, which is how Spotify genres
     * read ("melodic death metal", "lo-fi beats", "deep house").
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const RULES = [
        // Sleep / very low energy — most specific, checked first.
        ['sleep', 'sleep'],
        ['lullaby', 'sleep'],
        ['meditation', 'sleep'],

        // Ambient / atmospheric textures.
        ['ambient', 'ambient'],
        ['drone', 'ambient'],
        ['new age', 'ambient'],
        ['soundscape', 'ambient'],

        // Focus / study — lo-fi and instrumental study beats.
        ['lo-fi', 'focus'],
        ['lofi', 'focus'],
        ['study', 'focus'],
        ['instrumental hip hop', 'focus'],
        ['concentration', 'focus'],

        // Workout — gym / running / cardio cues.
        ['workout', 'workout'],
        ['gym', 'workout'],
        ['running', 'workout'],
        ['cardio', 'workout'],

        // Hype — aggressive, high-intensity genres.
        ['metal', 'hype'],
        ['punk', 'hype'],
        ['hardcore', 'hype'],
        ['thrash', 'hype'],
        ['trap', 'hype'],
        ['drum and bass', 'hype'],
        ['dubstep', 'hype'],

        // Party / dance — four-on-the-floor and club energy.
        ['party', 'party'],
        ['dance', 'party'],
        ['edm', 'party'],
        ['house', 'party'],
        ['techno', 'party'],
        ['disco', 'party'],
        ['club', 'party'],

        // Melancholy — sad / slow / blue moods.
        ['sad', 'melancholy'],
        ['slow', 'melancholy'],
        ['melancholy', 'melancholy'],
        ['blues', 'melancholy'],
        ['emo', 'melancholy'],
        ['shoegaze', 'melancholy'],

        // Chill — calm, mellow, laid-back.
        ['chill', 'chill'],
        ['classical', 'chill'],
        ['acoustic', 'chill'],
        ['jazz', 'chill'],
        ['bossa nova', 'chill'],
        ['lounge', 'chill'],

        // Upbeat — bright, happy, sunny.
        ['happy', 'upbeat'],
        ['pop', 'upbeat'],
        ['funk', 'upbeat'],
        ['reggae', 'upbeat'],
        ['ska', 'upbeat'],

        // Flow — groove-forward genres that don't fit a sharper bucket.
        ['hip hop', 'flow'],
        ['rap', 'flow'],
        ['r&b', 'flow'],
        ['soul', 'flow'],
        ['groove', 'flow'],
    ];

    /**
     * Resolve a list of genres to a single mood.
     *
     * WHY first-confident-match: an artist usually carries several genres
     * ("rock", "classic rock", "hard rock"); we scan them in order and return the
     * mood of the first genre that matches any rule, falling back to 'neutral'
     * when nothing is confident enough — exactly the value the theme already
     * degrades to gracefully.
     *
     * @param  array<int, string>  $genres
     */
    public function resolveMood(array $genres): string
    {
        foreach ($genres as $genre) {
            $mood = $this->moodForGenre($genre);
            if ($mood !== 'neutral') {
                return $mood;
            }
        }

        return 'neutral';
    }

    /**
     * Map a single genre string to a mood, or 'neutral' if no rule matches.
     *
     * Case-insensitive substring match so real Spotify genre strings
     * ("melodic death metal" → hype, "deep house" → party) resolve naturally.
     */
    public function moodForGenre(string $genre): string
    {
        $haystack = mb_strtolower(trim($genre));

        if ($haystack === '') {
            return 'neutral';
        }

        foreach (self::RULES as [$needle, $mood]) {
            if (str_contains($haystack, $needle)) {
                return $mood;
            }
        }

        return 'neutral';
    }
}
