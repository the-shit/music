<?php

namespace App\Commands;

use App\Services\SpotifyDiscoveryService;
use App\Support\CommitSoundtrack;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

class WrappedCommand extends Command
{
    protected $signature = 'wrapped
                            {--json : Output the raw stats as JSON instead of the card}
                            {--no-enrich : Skip the Spotify oEmbed lookup for missing track names}';

    protected $description = 'Your codebase, Spotify-Wrapped style: top tracks, moods and stats from the commit soundtrack';

    /** Inner width (columns) of the rendered card, between the side borders. */
    private const WIDTH = 48;

    public function handle(SpotifyDiscoveryService $discovery, CommitSoundtrack $soundtrack): int
    {
        $commits = $soundtrack->commits();

        if ($commits === []) {
            $this->components->info('No commits with Spotify track URLs found yet — go write some code to music.');

            return self::SUCCESS;
        }

        $stats = $soundtrack->stats($commits);

        if (! $this->option('no-enrich')) {
            $stats['top_tracks'] = $this->enrichNames($discovery, $stats['top_tracks']);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($this->buildCard($stats) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Fill in track names that the commit messages didn't carry, via Spotify
     * oEmbed (no auth). Best-effort: any failure leaves the existing value.
     *
     * @param  list<array{track_id:string,name:?string,artist:?string,count:int}>  $topTracks
     * @return list<array{track_id:string,name:?string,artist:?string,count:int}>
     */
    private function enrichNames(SpotifyDiscoveryService $discovery, array $topTracks): array
    {
        $missing = array_values(array_unique(array_map(
            fn (array $t): string => $t['track_id'],
            array_filter($topTracks, fn (array $t): bool => $t['name'] === null || $t['name'] === ''),
        )));

        if ($missing === []) {
            return $topTracks;
        }

        $meta = spin(
            fn (): array => $this->safeOEmbed($discovery, $missing),
            'Looking up track names…',
        );

        foreach ($topTracks as &$track) {
            if (($track['name'] === null || $track['name'] === '') && isset($meta[$track['track_id']])) {
                $track['name'] = $meta[$track['track_id']]['name'] ?? $track['name'];
                $track['artist'] ??= $meta[$track['track_id']]['artist'] ?? null;
            }
        }
        unset($track);

        return $topTracks;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string,array{name?:string,artist?:string}>
     */
    private function safeOEmbed(SpotifyDiscoveryService $discovery, array $ids): array
    {
        try {
            return $discovery->getTracksViaOEmbed($ids);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Render the wrapped card as a list of aligned lines. Pure (stats in, lines
     * out) so the layout is unit-testable and the borders provably line up.
     *
     * @param  array<string,mixed>  $stats
     * @return list<string>
     */
    public function buildCard(array $stats): array
    {
        $w = self::WIDTH;
        $lines = [];

        // Title baked into the top border.
        $title = ' THE·SHIT / MUSIC — WRAPPED ';
        $fill = $w - $this->width($title);
        $lines[] = '╭'.$title.str_repeat('─', max(0, $fill)).'╮';

        $row = fn (string $content = ''): string => '│'.$this->pad($content, $w).'│';

        $lines[] = $row();
        $lines[] = $row(sprintf('  %4d   commits set to music', $stats['total_commits']));
        $lines[] = $row(sprintf('  %4d   unique tracks', $stats['total_tracks']));
        $lines[] = $row();

        if ($stats['top_tracks'] !== []) {
            $lines[] = $row('  TOP TRACKS');
            $rank = 0;
            foreach ($stats['top_tracks'] as $track) {
                $rank++;
                if ($rank > 3) {
                    break;
                }
                $name = ($track['name'] ?? null) ?: substr($track['track_id'], 0, 12);
                // Reserve space for "  N  " prefix and "  ×NN" suffix.
                $count = sprintf('×%3d', $track['count']);
                $label = sprintf('  %d  %s', $rank, $name);
                $label = $this->truncate($label, $w - $this->width($count) - 3);
                $lines[] = $row($this->pad($label, $w - $this->width($count) - 3).'   '.$count);
            }
            $lines[] = $row();
        }

        if ($stats['top_artist'] !== null) {
            $lines[] = $row(sprintf('  Top artist   %s (%d)', $stats['top_artist']['name'], $stats['top_artist']['count']));
        }

        if ($stats['dominant_type'] !== null) {
            [$emoji, $word] = $this->vibe($stats['dominant_type']);
            $count = $stats['type_breakdown'][$stats['dominant_type']] ?? 0;
            $lines[] = $row(sprintf('  Top vibe     %s %s (%s ×%d)', $emoji, $word, $stats['dominant_type'], $count));
        }

        if ($stats['first_date'] !== null && $stats['last_date'] !== null) {
            $from = date('M Y', strtotime((string) $stats['first_date']));
            $to = date('M Y', strtotime((string) $stats['last_date']));
            $span = $from === $to ? $from : "{$from} → {$to}";
            $lines[] = $row('  '.$span);
        }

        $lines[] = $row();
        $lines[] = '╰'.str_repeat('─', $w).'╯';

        return $lines;
    }

    /**
     * A human "vibe" for a conventional-commit type.
     *
     * @return array{0:string,1:string} [emoji, word]
     */
    private function vibe(string $type): array
    {
        return match ($type) {
            'feat' => ['🚀', 'shipping'],
            'fix' => ['🔧', 'firefighting'],
            'docs' => ['📝', 'documenting'],
            'test' => ['🧪', 'testing'],
            'refactor' => ['🧹', 'tidying'],
            'chore' => ['📦', 'maintaining'],
            'ci' => ['🤖', 'automating'],
            'perf' => ['⚡', 'optimizing'],
            'style' => ['🎨', 'polishing'],
            'build' => ['🏗', 'building'],
            default => ['🎵', 'vibing'],
        };
    }

    /** Right-pad to a display width, truncating with an ellipsis if too long. */
    private function pad(string $s, int $width): string
    {
        $s = $this->truncate($s, $width);
        $gap = $width - $this->width($s);

        return $s.str_repeat(' ', max(0, $gap));
    }

    /** Truncate to a display width (mb-aware), appending '…' when clipped. */
    private function truncate(string $s, int $width): string
    {
        if ($this->width($s) <= $width) {
            return $s;
        }

        return rtrim(mb_strimwidth($s, 0, max(1, $width - 1), '', 'UTF-8')).'…';
    }

    private function width(string $s): int
    {
        return mb_strwidth($s, 'UTF-8');
    }
}
