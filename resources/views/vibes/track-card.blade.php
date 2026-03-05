@php
    $dominantType = 'feat';
    foreach ($group['commits'] as $commit) {
        if (preg_match('/^(feat|fix|test|ci|refactor|docs|chore)[\(:]/', $commit['subject'], $m)) {
            $dominantType = $m[1];
            break;
        }
    }
    $meta = $group['meta'] ?? null;
    $commitCount = count($group['commits']);
    $s = $commitCount === 1 ? '' : 's';
    $trackName = htmlspecialchars($meta['name'] ?? 'Unknown Track');
    $trackArtist = htmlspecialchars($meta['artist'] ?? 'Unknown Artist');
    $trackAlbum = htmlspecialchars($meta['album'] ?? '');
    $imageUrl = $meta['image_large'] ?? '';
    $imageMedium = $meta['image_medium'] ?? $imageUrl;
    $artHtml = $imageUrl ? '<div class="track-hero-bg" style="background-image: url(\'' . $imageUrl . '\')"></div><img class="track-art" src="' . $imageMedium . '" alt="' . $trackAlbum . '" loading="lazy">' : '';
    $trackId = $group['track_id'];
@endphp

<div class="track-card" data-type="{{ $dominantType }}">
    <div class="track-hero">
        {!! $artHtml !!}
        <div class="track-info">
            <div class="track-name">{{ $trackName }}</div>
            <div class="track-artist">{{ $trackArtist }}</div>
            <div class="track-album">{{ $trackAlbum }}</div>
        </div>
        <div class="track-badge">{{ $commitCount }} commit{{ $s }}</div>
    </div>
    <div class="spotify-embed">
        <iframe
            src="https://open.spotify.com/embed/track/{{ $trackId }}?utm_source=generator&theme=0"
            width="100%" height="80"
            frameBorder="0"
            allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
            loading="lazy">
        </iframe>
    </div>
    <div class="commits">
        <div class="commits-head">Commit History</div>
        <div class="commits-list">
            @foreach($group['commits'] as $commit)
                @include('vibes.commit', ['commit' => $commit])
            @endforeach
        </div>
    </div>
</div>