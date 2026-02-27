<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vibes — Commit Soundtrack</title>
    <meta name="description" content="Every commit has a vibe. {{ $totalCommits }} commits, {{ $totalTracks }} tracks.">
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
            padding: 96px 20px 72px;
            background:
                radial-gradient(circle at 15% 20%, rgba(29, 185, 84, 0.22), transparent 45%),
                radial-gradient(circle at 82% 0%, rgba(29, 185, 84, 0.14), transparent 42%),
                linear-gradient(180deg, #111 0%, #0a0a0a 100%);
            position: relative;
            overflow: hidden;
        }

        .hero h1 {
            font-size: 3.9rem;
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
            max-width: 980px;
            margin: 0 auto;
            padding: 0 20px 80px;
        }

        .track-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 32px;
            overflow: hidden;
            transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
            position: relative;
            border-left: 3px solid var(--green-dim);
        }

        .track-card[data-type="feat"] { border-left-color: var(--commit-type-feat); }
        .track-card[data-type="fix"] { border-left-color: var(--commit-type-fix); }
        .track-card[data-type="test"] { border-left-color: var(--commit-type-test); }
        .track-card[data-type="ci"] { border-left-color: var(--commit-type-ci); }
        .track-card[data-type="refactor"] { border-left-color: var(--commit-type-refactor); }
        .track-card[data-type="docs"] { border-left-color: var(--commit-type-docs); }

        .track-card:hover,
        .track-card.expanded {
            border-color: var(--green-dim);
            transform: translateY(-2px);
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.35);
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
            width: 110px;
            height: 110px;
            border-radius: 999px;
            object-fit: cover;
            position: relative;
            z-index: 1;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            transition: transform 0.5s ease;
        }

        .track-card:hover .track-art {
            transform: rotate(360deg);
        }

        .track-info {
            position: relative;
            z-index: 1;
            flex: 1;
            min-width: 0;
        }

        .track-name {
            font-size: 1.28rem;
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
            color: rgba Processed(255,255,255,0.4);
            margin-top: 2px;
        }

        .track-badge {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 8px 14px;
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
            padding: 0 24px 18px;
        }

        .commits-head {
            border-top: 1px solid var(--border);
            padding: 12px 0;
            color: var(--text-dim);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .commits-head::after {
            content: '▾';
            transition: transform 0.2s;
        }

        .track-card.expanded .commits-head::after {
            transform: rotate(180deg);
        }

        .commits-list {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease;
        }

        .track-card.expanded .commits-list {
            max-height: 640px;
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
        .type-chore { background: rgba(255, 255, 255, 0.1); color: rgba(255,255,255,0.8); }

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

        .top-vibe {
            color: var(--text-dim);
            font-size: 0.9rem;
            margin-top: 8px;
        }

        .top-vibe strong {
            color: var(--green);
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
            color: var(--textЗна-dim);
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
            .track-art { width: 72px; height: 72px; }
            .spotify-embed, .commits { padding-left: 16px; padding-right: 16px; }
            .track-badge { display: none; }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.commits-head').forEach(function (head) {
                head.addEventListener('click', function () {
                    head.closest('.track-card').classList.toggle('expanded');
                });
            });
        });
    </script>
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
        <h1>every commit has-task a <span>vibe</span></h1>
        <p class="subtitle">A Spotify CLI where CI rejects your code if you weren't listening to music when you wrote it.</p>
        <div class="stats">
            <div class="stat">
                <div class="stat-value">{{ $totalCommits }}</div>
                <div class="stat-label">Commits</div>
            </div>
            <div class="stat">
                <div class="stat-value">{{ $totalTracks }}</div>
                <div class="stat-label">Tracks</div>
            </div>
        </div>
        <p class="top-vibe">Top track: <strong>{{ $topTrack }}</strong> ({{ $topCommits }} commits)</p>
        {!! $playlistButton !!}
    </div>

    <div class="container">
        <h2 class="section-title">The Soundtrack</h2>

        @foreach($groups as $group)
            @include('vibes.track-card', ['group' => $group])
        @endforeach
    </div>

    <div class="footer">
        <strong>S.H.I.T.</strong> &mdash; Scaling Humans Into Tomorrow<br>
        Generated {{ $generatedAt }} &middot;
        Every commit is a <a href="https://github.com/the-shit/music">vibe check</a>
    </div>
</body>
</html>