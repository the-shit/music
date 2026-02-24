<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class VibesCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'vibes
                            {--output= : Output file path (default: docs/vibes.html)}
                            {--no-open : Don\'t open in browser}
                            {--playlist : Create/update a Spotify playlist of the soundtrack}
                            {--json : Output as JSON}';

    protected $description = 'Generate a page showing commits grouped by the song playing when they were written';

    public function handle(SpotifyService $spotify): int
    {
        $commits = spin(
            fn () => $this->parseGitLog(),
            'Parsing git history...'
        );

        if (empty($commits)) {
            info('No commits with Spotify track URLs found.');

            return self::SUCCESS;
        }

        $grouped = $this->groupByTrack($commits);

        // Fetch track metadata from Spotify API (album art, names)
        $trackIds = array_column($grouped, 'track_id');
        $trackMeta = [];
        if ($this->ensureConfigured()) {
            $trackMeta = spin(
                fn () => $spotify->getTracks($trackIds),
                'Fetching track metadata...'
            );
        }

        // Fall back to oEmbed for any tracks missing metadata (no auth needed)
        $missingIds = array_diff($trackIds, array_keys($trackMeta));
        if (! empty($missingIds)) {
            $oembedMeta = spin(
                fn () => $spotify->getTracksViaOEmbed($missingIds),
                'Fetching track info via oEmbed...'
            );
            $trackMeta = array_merge($trackMeta, $oembedMeta);
        }

        // Enrich groups with metadata
        foreach ($grouped as &$group) {
            $group['meta'] = $trackMeta[$group['track_id']] ?? null;
        }
        unset($group);

        if ($this->option('json')) {
            $this->line(json_encode([
                'total_commits' => count($commits),
                'total_tracks' => count($grouped),
                'tracks' => $grouped,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        // Generate playlist if requested
        $playlistUrl = null;
        if ($this->option('playlist')) {
            $playlistUrl = spin(
                fn () => $this->syncPlaylist($spotify, $grouped),
                'Syncing Spotify playlist...'
            );
            if ($playlistUrl) {
                info("Playlist synced: {$playlistUrl}");
            }
        }

        // Generate HTML
        $outputPath = $this->option('output') ?? base_path('docs/vibes.html');
        $outputDir = dirname($outputPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $html = $this->generateHtml($grouped, count($commits), $playlistUrl);
        file_put_contents($outputPath, $html);

        info("Generated vibes page: ".count($grouped)." tracks, ".count($commits)." commits");
        info("  {$outputPath}");

        if (! $this->option('no-open')) {
            Process::run("open '{$outputPath}'");
        }

        return self::SUCCESS;
    }

    private function parseGitLog(): array
    {
        $result = Process::run('git log --all --no-merges --format="COMMIT_START%n%H%n%an%n%ai%n%s%n%B%nCOMMIT_END"');

        if (! $result->successful()) {
            return [];
        }

        $output = $result->output();
        $commits = [];

        preg_match_all('/COMMIT_START\n(.+?)\nCOMMIT_END/s', $output, $matches);

        foreach ($matches[1] as $block) {
            $lines = explode("\n", $block, 5);
            if (count($lines) < 5) {
                continue;
            }

            [$hash, $author, $date, $subject, $body] = $lines;

            $fullMessage = $subject."\n".$body;
            if (preg_match('#https://open\.spotify\.com/track/([A-Za-z0-9]+)#', $fullMessage, $urlMatch)) {
                $commits[] = [
                    'hash' => trim($hash),
                    'short' => substr(trim($hash), 0, 7),
                    'author' => trim($author),
                    'date' => trim($date),
                    'subject' => trim($subject),
                    'track_url' => $urlMatch[0],
                    'track_id' => $urlMatch[1],
                ];
            }
        }

        return $commits;
    }

    private function groupByTrack(array $commits): array
    {
        $groups = [];

        foreach ($commits as $commit) {
            $trackId = $commit['track_id'];
            if (! isset($groups[$trackId])) {
                $groups[$trackId] = [
                    'track_id' => $trackId,
                    'track_url' => $commit['track_url'],
                    'commits' => [],
                ];
            }
            $groups[$trackId]['commits'][] = $commit;
        }

        usort($groups, fn ($a, $b) => count($b['commits']) <=> count($a['commits']));

        return $groups;
    }

    private function syncPlaylist(SpotifyService $spotify, array $groups): ?string
    {
        $playlistName = 'Commit Soundtrack';
        $description = 'Every track that was playing when code was committed. Auto-generated by spotify vibes.';

        // Find or create the playlist
        $playlist = $spotify->findPlaylistByName($playlistName);

        if (! $playlist) {
            $playlist = $spotify->createPlaylist($playlistName, $description);
            if (! $playlist) {
                warning('Could not create playlist');

                return null;
            }
        } else {
            $spotify->updatePlaylistDetails($playlist['id'], [
                'description' => $description.' Updated '.date('M j, Y'),
            ]);
        }

        // Collect unique track URIs in order (most-committed first)
        $uris = [];
        foreach ($groups as $group) {
            $meta = $group['meta'] ?? null;
            if ($meta) {
                $uris[] = $meta['uri'];
            } else {
                $uris[] = 'spotify:track:'.$group['track_id'];
            }
        }

        if (! empty($uris)) {
            $spotify->replacePlaylistTracks($playlist['id'], $uris);
        }

        return $playlist['external_urls']['spotify'] ?? "https://open.spotify.com/playlist/{$playlist['id']}";
    }

    private function generateHtml(array $groups, int $totalCommits, ?string $playlistUrl): string
    {
        $trackCards = '';
        foreach ($groups as $index => $group) {
            $trackCards .= $this->renderTrackCard($group, $index);
        }

        $totalTracks = count($groups);
        $generatedAt = date('F j, Y \a\t g:i A');
        $playlistButton = $playlistUrl
            ? "<a href=\"{$playlistUrl}\" class=\"playlist-link\" target=\"_blank\">Listen to the full playlist</a>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vibes — Commit Soundtrack</title>
    <meta name="description" content="Every commit has a vibe. {$totalCommits} commits, {$totalTracks} tracks.">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #0a0a0a;
            --surface: #141414;
            --surface-hover: #1a1a1a;
            --border: #282828;
            --text: #e1e1e1;
            --text-dim: #6a6a6a;
            --text-muted: #404040;
            --green: #1DB954;
            --green-dim: #1a7a3a;
            --commit-type-feat: #1DB954;
            --commit-type-fix: #e74c3c;
            --commit-type-test: #9b59b6;
            --commit-type-ci: #3498db;
            --commit-type-refactor: #f39c12;
            --commit-type-docs: #1abc9c;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            line-height: 1.6;
            min-height: 100vh;
        }

        .hero {
            text-align: center;
            padding: 80px 20px 60px;
            background: linear-gradient(180deg, #1DB95415 0%, transparent 100%);
            position: relative;
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 12px;
        }

        .hero h1 span { color: var(--green); }

        .hero .subtitle {
            color: var(--text-dim);
            font-size: 1.15rem;
            max-width: 520px;
            margin: 0 auto 24px;
        }

        .stats {
            display: flex;
            gap: 32px;
            justify-content: center;
            margin-top: 24px;
        }

        .stat {
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--green);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .playlist-link {
            display: inline-block;
            margin-top: 28px;
            padding: 12px 28px;
            background: var(--green);
            color: #000;
            font-weight: 700;
            font-size: 0.95rem;
            border-radius: 50px;
            text-decoration: none;
            transition: transform 0.15s, background 0.15s;
        }

        .playlist-link:hover {
            transform: scale(1.05);
            background: #1ed760;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px 80px;
        }

        .track-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 32px;
            overflow: hidden;
            transition: border-color 0.2s;
            position: relative;
        }

        .track-card:hover {
            border-color: var(--green-dim);
        }

        .track-hero {
            position: relative;
            padding: 24px;
            display: flex;
            gap: 20px;
            align-items: center;
            overflow: hidden;
        }

        .track-hero-bg {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            filter: blur(40px) brightness(0.3);
            transform: scale(1.5);
        }

        .track-art {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            position: relative;
            z-index: 1;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }

        .track-info {
            position: relative;
            z-index: 1;
            flex: 1;
            min-width: 0;
        }

        .track-name {
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .track-artist {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.7);
        }

        .track-album {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.4);
            margin-top: 2px;
        }

        .track-badge {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--green);
            white-space: nowrap;
        }

        .spotify-embed {
            padding: 0 24px;
            margin: 0 0 8px;
        }

        .spotify-embed iframe {
            border-radius: 12px;
        }

        .commits {
            padding: 0 24px 24px;
        }

        .commit {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-top: 1px solid var(--border);
            align-items: flex-start;
        }

        .commit:first-child {
            border-top: none;
        }

        .commit-hash {
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 0.8rem;
            color: var(--green);
            background: rgba(29, 185, 84, 0.08);
            padding: 2px 8px;
            border-radius: 4px;
            flex-shrink: 0;
            text-decoration: none;
        }

        .commit-hash:hover {
            background: rgba(29, 185, 84, 0.15);
        }

        .commit-info {
            flex: 1;
            min-width: 0;
        }

        .commit-subject {
            font-size: 0.9rem;
            color: var(--text);
            word-break: break-word;
        }

        .commit-type {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1px 6px;
            border-radius: 3px;
            margin-right: 4px;
        }

        .type-feat { background: rgba(29, 185, 84, 0.15); color: var(--commit-type-feat); }
        .type-fix { background: rgba(231, 76, 60, 0.15); color: var(--commit-type-fix); }
        .type-test { background: rgba(155, 89, 182, 0.15); color: var(--commit-type-test); }
        .type-ci { background: rgba(52, 152, 219, 0.15); color: var(--commit-type-ci); }
        .type-refactor { background: rgba(243, 156, 18, 0.15); color: var(--commit-type-refactor); }
        .type-docs { background: rgba(26, 188, 156, 0.15); color: var(--commit-type-docs); }

        .commit-meta {
            font-size: 0.75rem;
            color: var(--text-dim);
            margin-top: 2px;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .footer {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
            font-size: 0.8rem;
            border-top: 1px solid var(--border);
        }

        .nav {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            height: 56px;
            background: rgba(10, 10, 10, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
        }

        .nav-logo {
            font-weight: 800;
            font-size: 1.05rem;
            color: #fff;
            text-decoration: none;
            letter-spacing: -0.02em;
        }

        .nav-logo span { color: var(--green); }

        .nav-links {
            display: flex;
            gap: 24px;
            list-style: none;
        }

        .nav-links a {
            color: var(--text-dim);
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.15s;
        }

        .nav-links a:hover,
        .nav-links a.active { color: #fff; }

        .footer a {
            color: var(--green);
            text-decoration: none;
        }

        @media (max-width: 600px) {
            .hero h1 { font-size: 2.2rem; }
            .stats { gap: 20px; }
            .stat-value { font-size: 1.5rem; }
            .track-hero { padding: 16px; }
            .track-art { width: 60px; height: 60px; }
            .spotify-embed, .commits { padding-left: 16px; padding-right: 16px; }
            .track-badge { display: none; }
        }
    </style>
</head>
<body>
    <nav class="nav">
        <a href="index.html" class="nav-logo"><span>the-shit</span>/music</a>
        <ul class="nav-links">
            <li><a href="index.html">Home</a></li>
            <li><a href="commands.html">Commands</a></li>
            <li><a href="mcp.html">MCP</a></li>
            <li><a href="vibes.html" class="active">Vibes</a></li>
            <li><a href="https://github.com/the-shit/music">GitHub</a></li>
        </ul>
    </nav>

    <div class="hero">
        <h1>every commit has a <span>vibe</span></h1>
        <p class="subtitle">A Spotify CLI where CI rejects your code if you weren't listening to music when you wrote it.</p>
        <div class="stats">
            <div class="stat">
                <div class="stat-value">{$totalCommits}</div>
                <div class="stat-label">Commits</div>
            </div>
            <div class="stat">
                <div class="stat-value">{$totalTracks}</div>
                <div class="stat-label">Tracks</div>
            </div>
        </div>
        {$playlistButton}
    </div>

    <div class="container">
        <h2 class="section-title">The Soundtrack</h2>

        {$trackCards}
    </div>

    <div class="footer">
        <strong>S.H.I.T.</strong> &mdash; Scaling Humans Into Tomorrow<br>
        Generated {$generatedAt} &middot;
        Every commit is a <a href="https://github.com/the-shit/music">vibe check</a>
    </div>
</body>
</html>
HTML;
    }

    private function renderTrackCard(array $group, int $index): string
    {
        $trackId = $group['track_id'];
        $meta = $group['meta'] ?? null;
        $commitCount = count($group['commits']);
        $s = $commitCount === 1 ? '' : 's';

        // Track hero with album art
        $trackName = htmlspecialchars($meta['name'] ?? 'Unknown Track');
        $trackArtist = htmlspecialchars($meta['artist'] ?? 'Unknown Artist');
        $trackAlbum = htmlspecialchars($meta['album'] ?? '');
        $imageUrl = $meta['image_large'] ?? '';
        $imageMedium = $meta['image_medium'] ?? $imageUrl;

        $artHtml = $imageUrl
            ? "<div class=\"track-hero-bg\" style=\"background-image: url('{$imageUrl}')\"></div><img class=\"track-art\" src=\"{$imageMedium}\" alt=\"{$trackAlbum}\" loading=\"lazy\">"
            : '';

        $commitRows = '';
        foreach ($group['commits'] as $commit) {
            $commitRows .= $this->renderCommit($commit);
        }

        return <<<HTML

        <div class="track-card">
            <div class="track-hero">
                {$artHtml}
                <div class="track-info">
                    <div class="track-name">{$trackName}</div>
                    <div class="track-artist">{$trackArtist}</div>
                    <div class="track-album">{$trackAlbum}</div>
                </div>
                <div class="track-badge">{$commitCount} commit{$s}</div>
            </div>
            <div class="spotify-embed">
                <iframe
                    src="https://open.spotify.com/embed/track/{$trackId}?utm_source=generator&theme=0"
                    width="100%" height="80"
                    frameBorder="0"
                    allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
                    loading="lazy">
                </iframe>
            </div>
            <div class="commits">
                {$commitRows}
            </div>
        </div>
HTML;
    }

    private function renderCommit(array $commit): string
    {
        $hash = htmlspecialchars($commit['short']);
        $subject = htmlspecialchars($commit['subject']);
        $date = date('M j, Y', strtotime($commit['date']));
        $author = htmlspecialchars($commit['author']);

        $typeHtml = '';
        if (preg_match('/^(feat|fix|test|ci|refactor|docs|chore|style|perf)[\(:]/', $subject, $typeMatch)) {
            $type = $typeMatch[1];
            $cssClass = match ($type) {
                'feat' => 'type-feat',
                'fix' => 'type-fix',
                'test' => 'type-test',
                'ci' => 'type-ci',
                'refactor' => 'type-refactor',
                'docs' => 'type-docs',
                default => 'type-feat',
            };
            $typeHtml = "<span class=\"commit-type {$cssClass}\">{$type}</span>";
        }

        return <<<HTML

                <div class="commit">
                    <code class="commit-hash">{$hash}</code>
                    <div class="commit-info">
                        <div class="commit-subject">{$typeHtml}{$subject}</div>
                        <div class="commit-meta">{$author} · {$date}</div>
                    </div>
                </div>
HTML;
    }
}
