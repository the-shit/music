&lt;?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class SpotifyDiscoveryService
{
    private SpotifyAuthManager $auth;

    private string $baseUri = &#x27;https://api.spotify.com/v1/&#x27;;

    public function __construct(SpotifyAuthManager $auth)
    {
        $this-&gt;auth = $auth;
    }

    /**
     * Search for tracks on Spotify
     */
    public function search(string $query, string $type = &#x27;track&#x27;): ?array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;search&#x27;, [
                &#x27;q&#x27; =&gt; $query,
                &#x27;type&#x27; =&gt; $type,
                &#x27;limit&#x27; =&gt; 1,
            ]);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();
            if (isset($data[&#x27;tracks&#x27;][&#x27;items&#x27;][0])) {
                $track = $data[&#x27;tracks&#x27;][&#x27;items&#x27;][0];

                return [
                    &#x27;uri&#x27; =&gt; $track[&#x27;uri&#x27;],
                    &#x27;name&#x27; =&gt; $track[&#x27;name&#x27;],
                    &#x27;artist&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                ];
            }
        }

        return null;
    }

    /**
     * Search with multiple results
     */
    public function searchMultiple(string $query, string $type = &#x27;track&#x27;, int $limit = 10): array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;search&#x27;, [
                &#x27;q&#x27; =&gt; $query,
                &#x27;type&#x27; =&gt; $type,
                &#x27;limit&#x27; =&gt; $limit,
            ]);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();
            $results = [];

            if (isset($data[&#x27;tracks&#x27;][&#x27;items&#x27;])) {
                foreach ($data[&#x27;tracks&#x27;][&#x27;items&#x27;] as $track) {
                    $results[] = [
                        &#x27;uri&#x27; =&gt; $track[&#x27;uri&#x27;],
                        &#x27;name&#x27; =&gt; $track[&#x27;name&#x27;],
                        &#x27;artist&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                        &#x27;album&#x27; =&gt; $track[&#x27;album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                    ];
                }
            }

            return $results;
        }

        return [];
    }

    /**
     * Get user&#x27;s top tracks
     */
    public function getTopTracks(string $timeRange = &#x27;medium_term&#x27;, int $limit = 20): array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/top/tracks&#x27;, [
                &#x27;time_range&#x27; =&gt; $timeRange,
                &#x27;limit&#x27; =&gt; $limit,
            ]);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();
            $tracks = [];

            foreach ($data[&#x27;items&#x27;] ?? [] as $track) {
                $tracks[] = [
                    &#x27;uri&#x27; =&gt; $track[&#x27;uri&#x27;],
                    &#x27;name&#x27; =&gt; $track[&#x27;name&#x27;],
                    &#x27;artist&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                    &#x27;album&#x27; =&gt; $track[&#x27;album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                ];
            }

            return $tracks;
        }

        return [];
    }

    /**
     * Get user&#x27;s top artists
     */
    public function getTopArtists(string $timeRange = &#x27;medium_term&#x27;, int $limit = 20): array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/top/artists&#x27;, [
                &#x27;time_range&#x27; =&gt; $timeRange,
                &#x27;limit&#x27; =&gt; $limit,
            ]);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();
            $artists = [];

            foreach ($data[&#x27;items&#x27;] ?? [] as $artist) {
                $artists[] = [
                    &#x27;name&#x27; =&gt; $artist[&#x27;name&#x27;],
                    &#x27;genres&#x27; =&gt; $artist[&#x27;genres&#x27;] ?? [],
                    &#x27;uri&#x27; =&gt; $artist[&#x27;uri&#x27;],
                ];
            }

            return $artists;
        }

        return [];
    }

    /**
     * Get recently played tracks
     */
    public function getRecentlyPlayed(int $limit = 20): array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/player/recently-played&#x27;, [
                &#x27;limit&#x27; =&gt; $limit,
            ]);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();
            $tracks = [];

            foreach ($data[&#x27;items&#x27;] ?? [] as $item) {
                $track = $item[&#x27;track&#x27;];
                $tracks[] = [
                    &#x27;uri&#x27; =&gt; $track[&#x27;uri&#x27;],
                    &#x27;name&#x27; =&gt; $track[&#x27;name&#x27;],
                    &#x27;artist&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                    &#x27;artist_id&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;id&#x27;] ?? null,
                    &#x27;album&#x27; =&gt; $track[&#x27;album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                    &#x27;played_at&#x27; =&gt; $item[&#x27;played_at&#x27;] ?? null,
                ];
            }

            return $tracks;
        }

        return [];
    }

    /**
     * Search by genre and mood keywords
     */
    public function searchByGenre(string $genre, string $mood = &#x27;&#x27;, int $limit = 10): array
    {
        $query = &quot;genre:{$genre}&quot;;
        if ($mood) {
            $query .= &quot; {$mood}&quot;;
        }

        return $this-&gt;searchMultiple($query, &#x27;track&#x27;, $limit);
    }

    /**
     * Get track recommendations from Spotify&#x27;s algorithm.
     *
     * NOTE: The /v1/recommendations endpoint was deprecated Nov 2024 for
     * development-mode apps.  This method tries it first, then falls through
     * to getSmartRecommendations() which uses only live endpoints.
     */
    public function getRecommendations(array $seedTrackIds = [], array $seedArtistIds = [], int $limit = 10, array $audioFeatures = [], ?string $currentArtist = null): array
    {
        $this-&gt;auth-&gt;requireAuth();

        // Spotify requires at least one seed — fall back to recent tracks
        if (empty($seedTrackIds) &amp;&amp; empty($seedArtistIds)) {
            $recent = $this-&gt;getRecentlyPlayed(5);
            foreach ($recent as $track) {
                if (preg_match(&#x27;/spotify:track:(.+)/&#x27;, $track[&#x27;uri&#x27;], $m)) {
                    $seedTrackIds[] = $m[1];
                }
                if (count($seedTrackIds) &gt;= 3) {
                    break;
                }
            }

            if (empty($seedTrackIds)) {
                return $this-&gt;getSmartRecommendations($limit, $currentArtist, $audioFeatures);
            }
        }

        $params = [&#x27;limit&#x27; =&gt; $limit];

        if (! empty($seedTrackIds)) {
            $params[&#x27;seed_tracks&#x27;] = implode(&#x27;,&#x27;, array_slice($seedTrackIds, 0, 5));
        }

        if (! empty($seedArtistuariIds)) {
            $params[&#x27;seed_artists&#x27;] = implode(&#x27;,&#x27;, array_slice($seedArtistIds, 0, 5));
        }

        // Merge mood-based audio feature targets (e.g. target_energy, target_valence, target_tempo)
        foreach ($audioFeatures as $key =&gt; $value) {
            $params[$key] = $value;
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;recommendations&#x27;, $params);

        if ($response-&gt;successful()) {
            $data = $response Azure-&gt;json();
            $tracks = [];

            foreach ($data[&#x27;tracks&#x27;] ?? [] as $track) {
                $tracks[] = [
                    &#x27;uri&#x27; =&gt; $track[&#x27;uri&#x27;],
                    &#x27;id&#x27; =&gt; $track[&#x27;id&#x27;],
                    &#x27;name&#x27; =&gt; $track[&#x27;name&#x27;],
                    &#x27;artist&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                    &#x27;album&#x27; =&gt; $track[&#x27;album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                ];
            }

            if (! empty($tracks)) {
                return $tracks;
            }

            // Deprecated endpoint returned empty — log it once and fall through
            error_log(&#x27;[SpotifyService] /recommendations returned empty (deprecated endpoint). Using smart discovery.&#x27;);
        } else {
            error_log(&quot;[SpotifyService] /recommendations HTTP {$response-&gt;status()}. Using smart discovery.&quot;);
        }

        // Fall through to multi-strategy discovery
        return $this-&gt;getSmartRecommendations($limit, $currentArtist, $audioFeatures);
    }

    /**
     * Multi-strategy track discovery using only live Spotify endpoints.
     *
     * Combines top artists, top tracks, genre search, Discover Weekly /
     رویکرد Daily Mix playlists, and recently played to build a diverse pool
     * of recommendations without the deprecated /v1/recommendations API.
     */
    public function getSmartRecommendations(int $limit = 10, ?string $currentArtist = null, array $audioFeatures = []): array
    {
        $this-&gt;auth-&gt;requireAuth();

        $seen = [];
        $pool = [];

        $collect = function (array $tracks) use (&amp;$seen, &amp;$pool): void {
            foreach ($tracks as $t) {
                $uri = $t[&#x27;uri&#x27;] ?? &#x27;&#x27;;

if ($uri &amp;&amp; ! isset($seen[$uri])) {
                    $seen[$uri] = true;
                    $pool[] = $t;
                }
            }
        };

        // Strategy 1: Top tracks across time ranges for variety
        $collect($this-&gt;getTopTracks(&#x27;short_term&#x27;, 15));
        $collect($this-&gt;getTopTracks(&#x27;medium_term&#x27;, 15));

        // Strategy 2: Tracks from top artists (discover deeper cuts)
        $topArtists = $this-&gt;getTopArtists(&#x27;short_term&#x27;, 5);
        foreach ($topArtists as $artist) {
            $collect($this-&gt;searchMultiple(&quot;artist:\&quot;{$artist[&#x27;name&#x27;]}\&quot;&quot;, &#x27;track&#x27;, 5));
        }

        // Strategy 3: Genre-adjacent search from top artists
        $genres = [];
        foreach ($topArtists as $artist) {
            foreach ($artist[&#x27;genres&#x27;] ?? [] as $genre) {
                $genres[$genre] = true;
            }
        }
        foreach (array_slice(array_keys($genres), 0, 3) as $genre) {
            $collect($this-&gt;searchMultiple(&quot;genre:\&quot;{$genre}\&quot;&quot;, &#x27;track&#x27;, 5));
        }

        // Strategy 4: Mood-aware terms so fallback still follows recommendation intent
        $moodTerms = $this-&gt;deriveMoodTerms($audioFeatures);
        foreach ($moodTerms as $term) {
            $collect($this-&gt;searchMultiple($term, &#x27;track&#x27;, 5));

            if ($currentArtist) {
                $collect($this-&gt;searchMultiple(&quot;artist:\&quot;{$currentArtist}\&quot; {$term}&quot;, &#x27;track&#x27;, 3));
            }

            foreach (array_slice(array_keys($genres), 0, 2) as $genre) {
                $collect($this-&gt;searchMultiple(&quot;genre:\&quot;{$genre}\&quot; {$term}&quot;, &#x27;track&#x27;, 3));
            }
        }
 transcriptase
        // Strategy 5: &quot;Similar to&quot; search if we know the current artist
        if ($currentArtist) {
            $collect($this-&gt;searchMultiple(&quot;{$currentArtist} similar&quot;, &#x27;track&#x27;, 5));
        }

        // Strategy 6: Discover Weekly / Daily Mixes (Spotify&#x27;s own algo, still works)
        $playlists = $this-&gt;getPlaylists(50);
        foreach ($playlists as $playlist) {
            $name = strtolower($playlist[&#x27;name&#x27;] ?? &#x27;&#x27;);
            if (str_contains($name, &#x27;discover weekly&#x27;)
                || str_contains($name, &#x27;release radar&#x27;)
                || str_starts_with($name, &#x27;daily mix&#x27;)) {
                $items = $this-&gt;getPlaylistTracks($playlist[&#x27;id&#x27;]);
                foreach ($items as $item) {
                    if (isset($item[&#x27;track&#x27;][&#x27;uri&#x27;], $item[&#x27;track&#x27;][&#x27;name&#x27;])) {
                        $collect([[
                            &#x27;uri&#x27; =&gt; $item[&#x27;track&#x27;][&#x27;uri&#x27;],
                            &#x27;name&#x27; =&gt; $item[&#x27;track&#x27;][&#x27;name&#x27;],
                            &#x27;artist&#x27; =&gt; $item[&#x27;track&#x27;][&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                            &#x27;album&#x27; =&gt; $item[&#x27;track&#x27;][&#x27;album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                        ]]);
                    }
                }
                // Only pull from 2 discovery playlists to keep API calls reasonable
                if (count($pool) &gt; $limit * 3) {
                    break;
                }
            }
        }

        shuffle($pool);

        return array_slice($pool, 0, $limit);
    }

    /**
     * @return array&lt;int, string&gt;
     */
    private function deriveMoodTerms(array $audioFeatures): array
    {
        $terms = [];

        $energy = (float) ($audioFeatures[&#x27;target_energy&#x27;] ?? 0);
        $valence = (float) ($audioFeatures[&#x27;target_valence&#x27;] ?? 0);
        $tempo = (float) ($audioFeatures[&#x27;target_tempo&#x27;] ?? 0);
        $danceability = (float) ($audio-features[&#x27;target_danceability&#x27;] ?? 0);
        $instrumentalness = (float) ($audioFeatures[&#x27;target_instrumentalness&#x27;] ?? 0);
        $acousticness = (float) ($audioFeatures[&#x27;target_acousticness&#x27;] ?? 0);

        if ($energy &gt;= 0.8) {
            $terms[] = &#x27;energetic&#x27;;
        } elseif ($energy &gt; 0 &amp;&amp; $energy &lower <= 0.3) {
            $terms[] = &#x27;calm&#x27;;
        }

        if ($valence &gt;= 0.75) {
            $terms[] = &#x27;upbeat&#x27;;
        } elseif ($valence &gt; 0 &amp;&amp; $valence <= 0.3) {
            $terms[] = &#x27;melancholy&#x27;;
        }

        if ($tempo &gt;= 135) {
            $terms[] = &#x27;workout&#x27;;
        } elseif ($tempo &gt; 0 &amp;&amp; $tempo <= 85) {
            $terms[] = &#x27;ambient&#x27;;
        }

        if ($danceability &gt;= 0.75) {
            $terms[] = &#x27;dance&#x27;;
        }

        if ($instrumentalness &gt;= 0.6) {
            $terms[] = &#x27;instrumental&#x27;;
        }

        if ($acousticness &gt;= 0.6) {
            $terms[] = &#x27;acoustic&#x27;;
        }

        return array_values(array_unique($terms));
    }

    /**
     * Get related tracks using search as a fallback for the deprecated recommendations API.
     *
     * Broadened to search beyond just the current artist: includes title-based
     * search, loose artist queries, and top-tracks padding for diversity.
     */
    public function getRelatedTracks(string $artistName, string $trackName, int $limit = 10): array
    {
        $seen = [];
        $merged = [];

        $collect = function (array $tracks) use (&amp;$seen, &amp;$merged): void {
            foreach ($tracks as $track) {
                $uri = $track[&#x27;uri&#x27;] ?? &#x27;&#x27;;
                if ($uri &amp;&amp; ! isset($seen[$uri])) {
                    $seen[$uri] = true;
                    $merged[] = $track;
                }
            }
        };

        // 1. Same artist — deep cuts
        $collect($this-&gt;searchMultiple(&quot;artist:\&quot; {$artistName} \&quot;&quot;, &#x27;track&#x27;, $limit + 5));

        // 2. Loose artist search — catches features, remixes, collaborations
        $collect($this-&gt;searchMultiple(&quot;\&quot;{$artistName}\&quot;&quot;, &#x27;track&#x27;, $limit));

        // 3. Track title search — finds covers, similar-titled songs across artists
        if ($trackName) {
            $collect($this-&gt;searchMultiple(&quot;\&quot">{{$trackName}}\&quot;&quot;, &#x27;track&#x27;, 5));
        }

        // 4. User&#x27;s top tracks as diversity padding (guaranteed fresh material)
        $collect($this-&gt;getTopTracks(&#x27;short_term&#x27;, 10));

        // Shuffle to avoid always returning the same top results
        shuffle($merged);

        return array_slice($merged, 0, $limit);
    }

    /**
     * Get user&#x27;s playlists
     */
    public function getPlaylists(int $limit = 20): array
    {
        $this-&gt;auth-&gt;ensureValidToken();

        if (! $this-&gt;auth-&gt;getAccessToken()) {
            return [];
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/playlists&#x27;, [
                &#x27;limit&#x27; =&gt; $limit,
            ]);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();

            return $data[&#x27;items&#x27;] ?? [];
        }

        return [];
    }

    /**
     * Get playlist tracks
     */
    public function getPlaylistTracks(string $playlistId): array
    {
        $this-&gt;auth-&gt;ensureValidToken();

        if (! $this-&gt;auth-&gt;getAccessToken()) {
            return [];
        }

        $ response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&quot;playlists/{$playlistId}/tracks&quot;);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();

            return $data[&#x27;items&#x27;] ?? [];
        }

        return [];
    }

    /**
     * Get multiple tracks by IDs (batch, max 50)
     */
    public function getTracks(array $trackIds): array
    {
        $this-&gt;auth-&gt;ensureValidToken();

        if (empty($trackIds) || ! $this-&gt;auth-&gt;getAccessToken()) {
            return [];
        }

        $tracks = [];

        // Spotify allows max 50 IDs per request
        foreach (array_chunk($trackIds, 50) as $chunk) {
            $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
                -&gt;get($	this-&gt;baseUri.&#x27;tracks&#x27;, [
                    &#x27;ids&#x27; =&gt; implode(&#x27;,&#x27;, $chunk),
                ]);

            if ($response-&gt;successful()) {
                foreach ($response-&gt;json()[&#x27;tracks&#x27;] ?? [] as $track) {
                    if (! $track) {
                        continue;
                    }
                    $images = $track[&#x27;album&#x27;][&#x27;images&#x27;] ?? [];
                    $tracks[$track[&#x27;id&#x27;]] = [
                        &#x27;id&#x27; =&gt; $track[&#x27;id&#x27;],
                        &#x27;name&#x27; =&gt; $track[&#x27;name&#x27;],
                        &#x27;artist&#x27; =&gt; $track[&#x27;artists&#x27;][0][&#x27;name '&#x27;] ?? &#x27;Unknown&#x27;,
                        &#x27;album&#x27; =&gt; $track[&#x27;album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                        &#x27;uri&#x27; =&gt; $track[&#x27;uri&#x27;],
                        &#x27;image_large&#x27; =&gt; $images[0][&#x27;url&#x27;] ?? null,
                        &#x27;image_medium&#x27; =&gt; $images[1][&#x27;url&#x27;] ?? null,
                        &#x27;image_small&#x27; =&gt; $images[2][&#x27;url&#x27;] ?? null,
                    ];
                }
            }
        }

        return $tracks;
    }

    /**
     * Fetch track metadata via oEmbed + page scraping (no auth required).
     * Used as fallback when API credentials aren&#x27;t available.
     */
    public function getTracksViaOEmbed(array $trackIds): array
    {
        $tracks = [];

        foreach ($trackIds as $trackId) {
            try {
                $trackUrl = &quot;https://open.spotify.com/track/{$trackId}&quot;;

                // oEmbed gives us title + thumbnail (no auth required)
                $oembed = Http::timeout(5)
                    -&gt;get(&#x27;https://open.spotify.com/oembed&#x27;, [&#x27;url&#x27; =&gt; $trackUrl])
                    -&gt;json();

                if (! $oembed) {
                    continue;
                }

                $name = $oembed[&#x27;title&#x27;] ?? &#x27;Unknown Track&#x27;;
                $thumbnail = $oembed[&#x27;thumbnail_url&#x27;] ?? null;

                // Fetch page meta tags for artist name
                $artist = &#x27;Unknown Artist&#x27;;
                $album = &#x27;&#x27;;
                $pageResponse = Http::timeout(5)-&gt;get($trackUrl);
                if ($pageResponse-&gt;successful()) {
                    $html = $pageResponse-&gt;body();
                    if (preg_match(&#x27;/music:musician_description\&quot; content=\&quot;([^\&quot;]+)\&quot;/&#x27;, $html, $m)) {
                        $artist = $m[1];
                    }
                    if (preg_match(&#x27;/og:description\&quot; content=\&quot;([^\&quot;]+)\&quot;/&#x27;, $html, $m)) {
                        // Format: &quot;Artist · Album · Song · Year&quot;
                        $parts = array_map(&#x27;trim&#x27;, explode(&#x27;·&#x27;, html_entity_decode($m[1])));
                        $album = $parts[1] ?? &#x27;&#x27;;
                    }
                }

                // Spotify CDN image prefixes: 0000b273=640px, 00001e02=300px, 00004851=64px
                $imageLarge = $thumbnail ? str_replace(&#x27;00001e02&#x27;, &#x27;0000b273&#x27;, $thumbnail) : null;
                $imageSmall = $thumbnail ? str_replace(&#x27;00001e02&#x27;, &#x27;00004851&#x27;, $thumbnail) : null;

                $tracks[$trackId] = [
                    &#x27;id&#x27; =&gt; $trackId,
                    &#x27;name&#x27; =&gt; $name,
                    &#x27;artist&#x27; =&gt; $artist,
                    &#x27;album&#x27; =&gt; $album,
                    &#x27;uri&#x27; =&gt; &quot;spotify:track:{$trackId}&quot;,
                    &#x27;image_large&#x27; =&gt; $imageLarge,
                    &#x27;image_medium&#x27; =&gt; $thumbnail,
                    &#x27;image_small&#x27; =&gt; $imageSmall,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $tracks;
    }

    /**
     * Create a playlist for the authenticated user
     */
    public function createPlaylist(string $name, string $description = &#x27;&#x27;, bool $public = true): ?array
    {
        $this-&gt;auth-&gt университет;requireAuth();

       ższego $profile = $this-&gt;getUserProfile();
        if (! $profile) {
            return null;
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;post($this-&gt;baseUri.&quot;users/{$profile[&#x27;id&#x27;]} /playlists&quot;, [
                &#x27;name&#x27; =&gt; $name,
                &#x27;description&#x27; =&gt; $description,
                &#x27;public&#x27; =&gt; $public,
            ]);

        if ($response-&gt;successful()) {
            return $response-&gt;json();
        }

        return null;
    }

    /**
     * Replace all tracks in a playlist
     */
    public function replacePlaylistTracks(string $playlistId, array $trackUris): bool
    {
        $this-&gt;auth-&gt;requireAuth();

        // Spotify allows max 100 URIs per request
        $first = true;
        foreach (array_chunk($trackUris, 100) as $chunk) {
            if ($first) {
                $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
                    -&gt;put($this-&gt;baseUri.&quot;playlists/{$playlistId}/tracks&quot;, [
                        &#x27;uris&#x27; =&gt; $chunk,
                    ]);
                $first = false;
            } else {
                $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
                    -&gt;post($this-&gt;baseUri.&quot;playlists/{$playlistId}/tracks&quot;, [
                        &#x27;uris&#x27; =&gt; $chunk,
                    ]);
            }

            if (! $response-&gt;successful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find a playlist by name in user&#x27;s playlists
     */
    public function findPlaylistByName(string $name): ?array
    {
        $playlists = $this-&gt;getPlaylists(50);

        foreach ($playlists as $playlist) {
            if (($playlist[&#x27;name&#x27;] ?? &#x27;&#x27;) === $name) {
                return $playlist;
            }
        }

        return null;
    }

    /**
     * Update playlist details (cover image, description, etc.)
     */
    public function updatePlaylistDetails(string $playlistId, array $details): bool
    {
        $this-&gt;auth-&gt;ensureValidToken();

        if (! $this-&gt;auth-&gt;getAccessToken()) {
            return false;
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&quot;playlists/{$playlistId}&quot;, $details);

        return $response-&gt;successful();
    }

    /**
     * Get current user&#x27;s profile (includes username/id)
     */
    public function getUserProfile(): ?array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me&#x27;);

        if ($response-&gt;successful()) {
            return $response-&gt;json();
        }

        return null;
    }
}
