<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class SpotifyDiscoveryService
{
    private SpotifyAuthManager $auth;

    private string $baseUri = 'https://api.spotify.com/v1/';

    public function __construct(SpotifyAuthManager $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Search for tracks on Spotify
     */
    public function search(string $query, string $type = 'track'): ?array
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'search', [
                'q' => $query,
                'type' => $type,
                'limit' => 1,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['tracks']['items'][0])) {
                $track = $data['tracks']['items'][0];

                return [
                    'uri' => $track['uri'],
                    'name' => $track['name'],
                    'artist' => $track['artists'][0]['name'] ?? 'Unknown',
                ];
            }
        }

        return null;
    }

    /**
     * Search with multiple results
     */
    public function searchMultiple(string $query, string $type = 'track', int $limit = 10): array
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'search', [
                'q' => $query,
                'type' => $type,
                'limit' => $limit,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $results = [];

            if (isset($data['tracks']['items'])) {
                foreach ($data['tracks']['items'] as $track) {
                    $results[] = [
                        'uri' => $track['uri'],
                        'name' => $track['name'],
                        'artist' => $track['artists'][0]['name'] ?? 'Unknown',
                        'album' => $track['album']['name'] ?? 'Unknown',
                    ];
                }
            }

            return $results;
        }

        return [];
    }

    /**
     * Get user's top tracks
     */
    public function getTopTracks(string $timeRange = 'medium_term', int $limit = 20): array
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'me/top/tracks', [
                'time_range' => $timeRange,
                'limit' => $limit,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $tracks = [];

            foreach ($data['items'] ?? [] as $track) {
                $tracks[] = [
                    'uri' => $track['uri'],
                    'name' => $track['name'],
                    'artist' => $track['artists'][0]['name'] ?? 'Unknown',
                    'album' => $track['album']['name'] ?? 'Unknown',
                ];
            }

            return $tracks;
        }

        return [];
    }

    /**
     * Get user's top artists
     */
    public function getTopArtists(string $timeRange = 'medium_term', int $limit = 20): array
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'me/top/artists', [
                'time_range' => $timeRange,
                'limit' => $limit,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $artists = [];

            foreach ($data['items'] ?? [] as $artist) {
                $artists[] = [
                    'name' => $artist['name'],
                    'genres' => $artist['genres'] ?? [],
                    'uri' => $artist['uri'],
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
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'me/player/recently-played', [
                'limit' => $limit,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            $tracks = [];

            foreach ($data['items'] ?? [] as $item) {
                $track = $item['track'];
                $tracks[] = [
                    'uri' => $track['uri'],
                    'name' => $track['name'],
                    'artist' => $track['artists'][0]['name'] ?? 'Unknown',
                    'artist_id' => $track['artists'][0]['id'] ?? null,
                    'album' => $track['album']['name'] ?? 'Unknown',
                    'played_at' => $item['played_at'] ?? null,
                ];
            }

            return $tracks;
        }

        return [];
    }

    /**
     * Search by genre and mood keywords
     */
    public function searchByGenre(string $genre, string $mood = '', int $limit = 10): array
    {
        $query = "genre:{$genre}";
        if ($mood) {
            $query .= " {$mood}";
        }

        return $this->searchMultiple($query, 'track', $limit);
    }

    /**
     * Get track recommendations from Spotify's algorithm.
     *
     * NOTE: The /v1/recommendations endpoint was deprecated Nov 2024 for
     * development-mode apps.  This method tries it first, then falls through
     * to getSmartRecommendations() which uses only live endpoints.
     */
    public function getRecommendations(array $seedTrackIds = [], array $seedArtistIds = [], int $limit = 10, array $audioFeatures = [], ?string $currentArtist = null): array
    {
        $this->auth->requireAuth();

        // Spotify requires at least one seed — fall back to recent tracks
        if (empty($seedTrackIds) && empty($seedArtistIds)) {
            $recent = $this->getRecentlyPlayed(5);
            foreach ($recent as $track) {
                if (preg_match('/spotify:track:(.+)/', $track['uri'], $m)) {
                    $seedTrackIds[] = $m[1];
                }
                if (count($seedTrackIds) >= 3) {
                    break;
                }
            }

            if (empty($seedTrackIds)) {
                return $this->getSmartRecommendations($limit, $currentArtist, $audioFeatures);
            }
        }

        $params = ['limit' => $limit];

        if (! empty($seedTrackIds)) {
            $params['seed_tracks'] = implode(',', array_slice($seedTrackIds, 0, 5));
        }

        if (! empty($seedArtistIds)) {
            $params['seed_artists'] = implode(',', array_slice($seedArtistIds, 0, 5));
        }

        // Merge mood-based audio feature targets (e.g. target_energy, target_valence, target_tempo)
        foreach ($audioFeatures as $key => $value) {
            $params[$key] = $value;
        }

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'recommendations', $params);

        if ($response->successful()) {
            $data = $response->json();
            $tracks = [];

            foreach ($data['tracks'] ?? [] as $track) {
                $tracks[] = [
                    'uri' => $track['uri'],
                    'id' => $track['id'],
                    'name' => $track['name'],
                    'artist' => $track['artists'][0]['name'] ?? 'Unknown',
                    'album' => $track['album']['name'] ?? 'Unknown',
                ];
            }

            if (! empty($tracks)) {
                return $tracks;
            }

            // Deprecated endpoint returned empty — log it once and fall through
            
        } else {
            
        }

        // Fall through to multi-strategy discovery
        return $this->getSmartRecommendations($limit, $currentArtist, $audioFeatures);
    }

    /**
     * Multi-strategy track discovery using only live Spotify endpoints.
     *
     * Combines top artists, top tracks, genre search, Discover Weekly /
     * Daily Mix playlists, and recently played to build a diverse pool
     * of recommendations without the deprecated /v1/recommendations API.
     */
    public function getSmartRecommendations(int $limit = 10, ?string $currentArtist = null, array $audioFeatures = []): array
    {
        $this->auth->requireAuth();

        $seen = [];
        $pool = [];

        $collect = function (array $tracks) use (&$seen, &$pool): void {
            foreach ($tracks as $t) {
                $uri = $t['uri'] ?? '';

if ($uri && ! isset($seen[$uri])) {
                    $seen[$uri] = true;
                    $pool[] = $t;
                }
            }
        };

        // Strategy 1: Top tracks across time ranges for variety
        $collect($this->getTopTracks('short_term', 15));
        $collect($this->getTopTracks('medium_term', 15));

        // Strategy 2: Tracks from top artists (discover deeper cuts)
        $topArtists = $this->getTopArtists('short_term', 5);
        foreach ($topArtists as $artist) {
            $collect($this->searchMultiple("artist:\"{$artist['name']}\"", 'track', 5));
        }

        // Strategy 3: Genre-adjacent search from top artists
        $genres = [];
        foreach ($topArtists as $artist) {
            foreach ($artist['genres'] ?? [] as $genre) {
                $genres[$genre] = true;
            }
        }
        foreach (array_slice(array_keys($genres), 0, 3) as $genre) {
            $collect($this->searchMultiple("genre:\"{$genre}\"", 'track', 5));
        }

        // Strategy 4: Mood-aware terms so fallback still follows recommendation intent
        $moodTerms = $this->deriveMoodTerms($audioFeatures);
        foreach ($moodTerms as $term) {
            $collect($this->searchMultiple($term, 'track', 5));

            if ($currentArtist) {
                $collect($this->searchMultiple("artist:\"{$currentArtist}\" {$term}", 'track', 3));
            }

            foreach (array_slice(array_keys($genres), 0, 2) as $genre) {
                $collect($this->searchMultiple("genre:\"{$genre}\" {$term}", 'track', 3));
            }
        }

        // Strategy 5: "Similar to" search if we know the current artist
        if ($currentArtist) {
            $collect($this->searchMultiple("{$currentArtist} similar", 'track', 5));
        }

        // Strategy 6: Discover Weekly / Daily Mixes (Spotify's own algo, still works)
        $playlists = $this->getPlaylists(50);
        foreach ($playlists as $playlist) {
            $name = strtolower($playlist['name'] ?? '');
            if (str_contains($name, 'discover weekly')
                || str_contains($name, 'release radar')
                || str_starts_with($name, 'daily mix')) {
                $items = $this->getPlaylistTracks($playlist['id']);
                foreach ($items as $item) {
                    if (isset($item['track']['uri'], $item['track']['name'])) {
                        $collect([[
                            'uri' => $item['track']['uri'],
                            'name' => $item['track']['name'],
                            'artist' => $item['track']['artists'][0]['name'] ?? 'Unknown',
                            'album' => $item['track']['album']['name'] ?? 'Unknown',
                        ]]);
                    }
                }
                // Only pull from 2 discovery playlists to keep API calls reasonable
                if (count($pool) > $limit * 3) {
                    break;
                }
            }
        }

        shuffle($pool);

        return array_slice($pool, 0, $limit);
    }

    /**
     * @return array<int, string>
     */
    private function deriveMoodTerms(array $audioFeatures): array
    {
        $terms = [];

        $energy = (float) ($audioFeatures['target_energy'] ?? 0);
        $valence = (float) ($audioFeatures['target_valence'] ?? 0);
        $tempo = (float) ($audioFeatures['target_tempo'] ?? 0);
        $danceability = (float) ($audioFeatures['target_danceability'] ?? 0);
        $instrumentalness = (float) ($audioFeatures['target_instrumentalness'] ?? 0);
        $acousticness = (float) ($audioFeatures['target_acousticness'] ?? 0);

        if ($energy >= 0.8) {
            $terms[] = 'energetic';
        } elseif ($energy > 0 && $energy <= 0.3) {
            $terms[] = 'calm';
        }

        if ($valence >= 0.75) {
            $terms[] = 'upbeat';
        } elseif ($valence > 0 && $valence <= 0.3) {
            $terms[] = 'melancholy';
        }

        if ($tempo >= 135) {
            $terms[] = 'workout';
        } elseif ($tempo > 0 && $tempo <= 85) {
            $terms[] = 'ambient';
        }

        if ($danceability >= 0.75) {
            $terms[] = 'dance';
        }

        if ($instrumentalness >= 0.6) {
            $terms[] = 'instrumental';
        }

        if ($acousticness >= 0.6) {
            $terms[] = 'acoustic';
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

        $collect = function (array $tracks) use (&$seen, &$merged): void {
            foreach ($tracks as $track) {
                $uri = $track['uri'] ?? '';
                if ($uri && ! isset($seen[$uri])) {
                    $seen[$uri] = true;
                    $merged[] = $track;
                }
            }
        };

        // 1. Same artist — deep cuts
        $collect($this->searchMultiple("artist:\" {$artistName} \"", 'track', $limit + 5));

        // 2. Loose artist search — catches features, remixes, collaborations
        $collect($this->searchMultiple("\"{$artistName}\"", 'track', $limit));

        // 3. Track title search — finds covers, similar-titled songs across artists
        if ($trackName) {
            $collect($this->searchMultiple("\"{$trackName}\"", 'track', 5));
        }

        // 4. User's top tracks as diversity padding (guaranteed fresh material)
        $collect($this->getTopTracks('short_term', 10));

        // Shuffle to avoid always returning the same top results
        shuffle($merged);

        return array_slice($merged, 0, $limit);
    }

    /**
     * Get user's playlists
     */
    public function getPlaylists(int $limit = 20): array
    {
        if (! $this->auth->getAccessToken()) {
            return [];
        }

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'me/playlists', [
                'limit' => $limit,
            ]);

        if ($response->successful()) {
            $data = $response->json();

            return $data['items'] ?? [];
        }

        return [];
    }

    /**
     * Get playlist tracks
     */
    public function getPlaylistTracks(string $playlistId): array
    {
        if (! $this->auth->getAccessToken()) {
            return [];
        }

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri."playlists/{$playlistId}/tracks");

        if ($response->successful()) {
            $data = $response->json();

            return $data['items'] ?? [];
        }

        return [];
    }

    /**
     * Get multiple tracks by IDs (batch, max 50)
     */
    public function getTracks(array $trackIds): array
    {
        if (empty($trackIds) || ! $this->auth->getAccessToken()) {
            return [];
        }

        $tracks = [];

        // Spotify allows max 50 IDs per request
        foreach (array_chunk($trackIds, 50) as $chunk) {
            $response = Http::withToken($this->auth->getAccessToken())
                ->get($this->baseUri.'tracks', [
                    'ids' => implode(',', $chunk),
                ]);

            if ($response->successful()) {
                foreach ($response->json()['tracks'] ?? [] as $track) {
                    if (! $track) {
                        continue;
                    }
                    $images = $track['album']['images'] ?? [];
                    $tracks[$track['id']] = [
                        'id' => $track['id'],
                        'name' => $track['name'],
                        'artist' => $track['artists'][0]['name'] ?? 'Unknown',
                        'album' => $track['album']['name'] ?? 'Unknown',
                        'uri' => $track['uri'],
                        'image_large' => $images[0]['url'] ?? null,
                        'image_medium' => $images[1]['url'] ?? null,
                        'image_small' => $images[2]['url'] ?? null,
                    ];
                }
            }
        }

        return $tracks;
    }

    /**
     * Fetch track metadata via oEmbed + page scraping (no auth required).
     * Used as fallback when API credentials aren't available.
     */
    public function getTracksViaOEmbed(array $trackIds): array
    {
        $tracks = [];

        foreach ($trackIds as $trackId) {
            try {
                $trackUrl = "https://open.spotify.com/track/{$trackId}";

                // oEmbed gives us title + thumbnail (no auth required)
                $oembed = Http::timeout(5)
                    ->get('https://open.spotify.com/oembed', ['url' => $trackUrl])
                    ->json();

                if (! $oembed) {
                    continue;
                }

                $name = $oembed['title'] ?? 'Unknown Track';
                $thumbnail = $oembed['thumbnail_url'] ?? null;

                // Fetch page meta tags for artist name
                $artist = 'Unknown Artist';
                $album = '';
                $pageResponse = Http::timeout(5)->get($trackUrl);
                if ($pageResponse->successful()) {
                    $html = $pageResponse->body();
                    if (preg_match('/music:musician_description\" content=\"([^\"]+)\"/', $html, $m)) {
                        $artist = $m[1];
                    }
                    if (preg_match('/og:description\" content=\"([^\"]+)\"/', $html, $m)) {
                        // Format: "Artist · Album · Song · Year"
                        $parts = array_map('trim', explode('·', html_entity_decode($m[1])));
                        $album = $parts[1] ?? '';
                    }
                }

                // Spotify CDN image prefixes: 0000b273=640px, 00001e02=300px, 00004851=64px
                $imageLarge = $thumbnail ? str_replace('00001e02', '0000b273', $thumbnail) : null;
                $imageSmall = $thumbnail ? str_replace('00001e02', '00004851', $thumbnail) : null;

                $tracks[$trackId] = [
                    'id' => $trackId,
                    'name' => $name,
                    'artist' => $artist,
                    'album' => $album,
                    'uri' => "spotify:track:{$trackId}",
                    'image_large' => $imageLarge,
                    'image_medium' => $thumbnail,
                    'image_small' => $imageSmall,
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
    public function createPlaylist(string $name, string $description = '', bool $public = true): ?array
    {
        $this->auth->requireAuth();

        $profile = $this->getUserProfile();
        if (! $profile) {
            return null;
        }

        $response = Http::withToken($this->auth->getAccessToken())
            ->post($this->baseUri."users/{$profile['id']}/playlists", [
                'name' => $name,
                'description' => $description,
                'public' => $public,
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Replace all tracks in a playlist
     */
    public function replacePlaylistTracks(string $playlistId, array $trackUris): bool
    {
        $this->auth->requireAuth();

        // Spotify allows max 100 URIs per request
        $first = true;
        foreach (array_chunk($trackUris, 100) as $chunk) {
            if ($first) {
                $response = Http::withToken($this->auth->getAccessToken())
                    ->put($this->baseUri."playlists/{$playlistId}/tracks", [
                        'uris' => $chunk,
                    ]);
                $first = false;
            } else {
                $response = Http::withToken($this->auth->getAccessToken())
                    ->post($this->baseUri."playlists/{$playlistId}/tracks", [
                        'uris' => $chunk,
                    ]);
            }

            if (! $response->successful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find a playlist by name in user's playlists
     */
    public function findPlaylistByName(string $name): ?array
    {
        $playlists = $this->getPlaylists(50);

        foreach ($playlists as $playlist) {
            if (($playlist['name'] ?? '') === $name) {
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
        if (! $this->auth->getAccessToken()) {
            return false;
        }

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri."playlists/{$playlistId}", $details);

        return $response->successful();
    }

    /**
     * Get current user's profile (includes username/id)
     */
    public function getUserProfile(): ?array
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->get($this->baseUri.'me');

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }
}
