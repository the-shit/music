<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SpotifyService
{
    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    private ?int $expiresAt = null;

    private ?string $clientId;

    private ?string $clientSecret;

    private string $baseUri = 'https://api.spotify.com/v1/';

    private string $tokenFile;

    public function __construct()
    {
        $this->clientId = config('spotify.client_id', '');
        $this->clientSecret = config('spotify.client_secret', '');

        // Use config path for PHAR compatibility (base_path() doesn't work in PHAR)
        $this->tokenFile = config('spotify.token_path');

        $this->loadTokenData();
    }

    /**
     * Check if Spotify is properly configured
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret)
            && (! empty($this->accessToken) || ! empty($this->refreshToken));
    }

    /**
     * Load token data from storage
     */
    private function loadTokenData(): void
    {
        if (file_exists($this->tokenFile)) {
            $data = json_decode(file_get_contents($this->tokenFile), true);
            if ($data) {
                $this->accessToken = $data['access_token'] ?? null;
                $this->refreshToken = $data['refresh_token'] ?? null;
                $this->expiresAt = $data['expires_at'] ?? null;
            }
        }
    }

    /**
     * Re-read credentials and token data fresh from disk, bypassing config cache.
     * Picks up tokens saved by external processes (e.g. `spotify login`).
     */
    private function reloadFromDisk(): void
    {
        $configDir = dirname($this->tokenFile);
        $credentialsFile = $configDir.'/credentials.json';

        if (file_exists($credentialsFile)) {
            $creds = json_decode(file_get_contents($credentialsFile), true);
            if ($creds) {
                $this->clientId = $creds['client_id'] ?? $this->clientId;
                $this->clientSecret = $creds['client_secret'] ?? $this->clientSecret;
            }
        }

        $this->loadTokenData();
    }

    /**
     * Check if token is expired and refresh if needed
     */
    private function ensureValidToken(): void
    {
        // If we have a refresh token and the access token is expired (or about to expire in 60 seconds)
        if ($this->refreshToken && (! $this->expiresAt || $this->expiresAt < (time() + 60))) {
            // Re-read from disk first — an external `spotify login` may have saved fresh tokens
            $this->reloadFromDisk();

            // After reload, check if we still need to refresh
            if (! $this->expiresAt || $this->expiresAt < (time() + 60)) {
                $this->refreshAccessToken();
            }

            if (! $this->accessToken) {
                throw new \Exception('Session expired. Run "spotify login" to re-authenticate.');
            }
        }
    }

    /**
     * Ensure we have a valid access token, throwing if not authenticated.
     * Combines the null-check + refresh into one call.
     */
    private function requireAuth(): void
    {
        if (! $this->accessToken && ! $this->refreshToken) {
            throw new \Exception('Not authenticated. Run "spotify login" first.');
        }

        $this->ensureValidToken();

        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "spotify login" first.');
        }
    }

    /**
     * Refresh the access token using refresh token
     */
    private function refreshAccessToken(): void
    {
        // Retry refresh up to 3 times — transient failures shouldn't nuke the session
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            ])->asForm()->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];
                $this->expiresAt = time() + ($data['expires_in'] ?? 3600);

                // Spotify may rotate refresh tokens — always capture the new one
                if (! empty($data['refresh_token'])) {
                    $this->refreshToken = $data['refresh_token'];
                }

                // Save updated token data
                $this->saveTokenData();

                return;
            }

            // Only clear token on 4xx (truly revoked), not on network/5xx errors
            if ($response->status() >= 400 && $response->status() < 500) {
                $this->accessToken = null;
                $this->expiresAt = null;
                $this->saveTokenData();

                return;
            }

            if ($attempt < 3) {
                usleep(500000 * $attempt);
            }
        }
    }

    /**
     * Save token data to file
     */
    private function saveTokenData(): void
    {
        $tokenData = [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
        ];

        // Ensure config directory exists (PHAR compatible path)
        $configDir = dirname($this->tokenFile);
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // Save with restricted permissions
        file_put_contents($this->tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));
        chmod($this->tokenFile, 0600); // Only owner can read/write
    }

    /**
     * Search for tracks on Spotify
     */
    public function search(string $query, string $type = 'track'): ?array
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
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
     * Play a track by URI
     */
    public function play(string $uri, ?string $deviceId = null): void
    {
        $this->requireAuth();

        // If no device specified, try to get active or first available
        if (! $deviceId) {
            $device = $this->getActiveDevice();
            if (! $device) {
                throw new \Exception('No Spotify devices available. Open Spotify on any device.');
            }

            // If device exists but not active, activate it
            if (! ($device['is_active'] ?? false)) {
                $this->transferPlayback($device['id'], false);
                usleep(500000); // Give Spotify 0.5s to activate
            }

            $deviceId = $device['id'];
        } else {
            // Device was specified, need to transfer to it first
            $devices = $this->getDevices();
            $targetDevice = null;
            foreach ($devices as $device) {
                if ($device['id'] === $deviceId) {
                    $targetDevice = $device;
                    break;
                }
            }

            // If target device is not active, transfer to it
            if ($targetDevice && ! $targetDevice['is_active']) {
                $this->transferPlayback($deviceId, false);
                usleep(500000); // Give Spotify 0.5s to activate
            }
        }

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/play', [
                'device_id' => $deviceId,
                'uris' => [$uri],
            ]);

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to play track');
        }
    }

    /**
     * Resume playback
     */
    public function resume(?string $deviceId = null): void
    {
        $this->requireAuth();

        // If no device specified, try to get active device
        if (! $deviceId) {
            $device = $this->getActiveDevice();
            if (! $device) {
                throw new \Exception('No Spotify devices available. Open Spotify on any device.');
            }

            // If device exists but not active, activate it
            if (! ($device['is_active'] ?? false)) {
                $this->transferPlayback($device['id'], true);

                return; // Transfer with play=true will resume
            }

            $deviceId = $device['id'];
        }

        $body = $deviceId ? ['device_id' => $deviceId] : [];

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/play', $body);

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to resume playback');
        }
    }

    /**
     * Pause playback
     */
    public function pause(): void
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/pause');

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to pause playback');
        }
    }

    /**
     * Set volume
     */
    public function setVolume(int $volumePercent): bool
    {
        $this->requireAuth();

        // Clamp volume to 0-100
        $volumePercent = max(0, min(100, $volumePercent));

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/volume?volume_percent='.$volumePercent);

        return $response->successful();
    }

    /**
     * Skip to next track
     */
    public function next(): void
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->post($this->baseUri.'me/player/next');

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to skip track');
        }
    }

    /**
     * Skip to previous track
     */
    public function previous(): void
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->post($this->baseUri.'me/player/previous');

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to skip to previous');
        }
    }

    /**
     * Get available devices
     */
    public function getDevices(): array
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->get($this->baseUri.'me/player/devices');

        if ($response->successful()) {
            $data = $response->json();

            return $data['devices'] ?? [];
        }

        return [];
    }

    /**
     * Transfer playback to a device
     */
    public function transferPlayback(string $deviceId, bool $play = true): void
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player', [
                'device_ids' => [$deviceId],
                'play' => $play,
            ]);

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to transfer playback');
        }
    }

    /**
     * Get active device or first available
     */
    public function getActiveDevice(): ?array
    {
        $devices = $this->getDevices();

        // First try to find active device
        foreach ($devices as $device) {
            if ($device['is_active'] ?? false) {
                return $device;
            }
        }

        // Return first available device
        return $devices[0] ?? null;
    }

    /**
     * Add a track to the queue
     */
    public function addToQueue(string $uri): void
    {
        $this->requireAuth();

        // Get active device first
        $device = $this->getActiveDevice();
        if (! $device) {
            throw new \Exception('No active Spotify device. Start playing something first.');
        }

        // The queue endpoint expects uri as a query parameter, not in the body
        $response = Http::withToken($this->accessToken)
            ->post($this->baseUri.'me/player/queue?'.http_build_query([
                'uri' => $uri,
                'device_id' => $device['id'],
            ]));

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to add to queue');
        }
    }

    /**
     * Get user's playlists
     */
    public function getPlaylists(int $limit = 20): array
    {
        if (! $this->accessToken && ! $this->refreshToken) {
            return [];
        }

        $this->ensureValidToken();

        $response = Http::withToken($this->accessToken)
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
        if (! $this->accessToken && ! $this->refreshToken) {
            return [];
        }

        $this->ensureValidToken();

        $response = Http::withToken($this->accessToken)
            ->get($this->baseUri."playlists/{$playlistId}/tracks");

        if ($response->successful()) {
            $data = $response->json();

            return $data['items'] ?? [];
        }

        return [];
    }

    /**
     * Play a playlist
     */
    public function playPlaylist(string $playlistId, ?string $deviceId = null): bool
    {
        if (! $this->accessToken && ! $this->refreshToken) {
            return false;
        }

        $this->ensureValidToken();

        $device = $deviceId ?: $this->getActiveDevice()['id'] ?? null;

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/play', [
                'device_id' => $device,
                'context_uri' => "spotify:playlist:{$playlistId}",
            ]);

        return $response->successful();
    }

    /**
     * Get queue
     */
    public function getQueue(): array
    {
        if (! $this->accessToken && ! $this->refreshToken) {
            return [];
        }

        $this->ensureValidToken();

        $response = Http::withToken($this->accessToken)
            ->get($this->baseUri.'me/player/queue');

        if ($response->successful()) {
            $data = $response->json();

            return [
                'currently_playing' => $data['currently_playing'] ?? null,
                'queue' => $data['queue'] ?? [],
            ];
        }

        return [];
    }

    /**
     * Search with multiple results
     */
    public function searchMultiple(string $query, string $type = 'track', int $limit = 10): array
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
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
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
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
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
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
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
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
    public function getRecommendations(array $seedTrackIds = [], array $seedArtistIds = [], int $limit = 10, array $audioFeatures = []): array
    {
        $this->requireAuth();

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
                return [];
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

        $response = Http::withToken($this->accessToken)
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
            error_log('[SpotifyService] /recommendations returned empty (deprecated endpoint). Using smart discovery.');
        } else {
            error_log("[SpotifyService] /recommendations HTTP {$response->status()}. Using smart discovery.");
        }

        // Fall through to multi-strategy discovery
        return $this->getSmartRecommendations($limit);
    }

    /**
     * Multi-strategy track discovery using only live Spotify endpoints.
     *
     * Combines top artists, top tracks, genre search, Discover Weekly /
     * Daily Mix playlists, and recently played to build a diverse pool
     * of recommendations without the deprecated /v1/recommendations API.
     */
    public function getSmartRecommendations(int $limit = 10, ?string $currentArtist = null): array
    {
        $this->requireAuth();

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

        // Strategy 4: "Similar to" search if we know the current artist
        if ($currentArtist) {
            $collect($this->searchMultiple("{$currentArtist} similar", 'track', 5));
        }

        // Strategy 5: Discover Weekly / Daily Mixes (Spotify's own algo, still works)
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
        $collect($this->searchMultiple("artist:\"{$artistName}\"", 'track', $limit + 5));

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
     * Set shuffle state
     */
    public function setShuffle(bool $state): bool
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/shuffle?state='.($state ? 'true' : 'false'));

        return $response->successful();
    }

    /**
     * Set repeat mode
     */
    public function setRepeat(string $state): bool
    {
        $this->requireAuth();

        // State can be: off, track, context
        if (! in_array($state, ['off', 'track', 'context'])) {
            throw new \Exception('Invalid repeat state. Use: off, track, or context');
        }

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/repeat?state='.$state);

        return $response->successful();
    }

    /**
     * Get current playback state
     */
    public function getCurrentPlayback(): ?array
    {
        if (! $this->accessToken && ! $this->refreshToken) {
            return null;
        }

        $this->ensureValidToken();

        // Use /me/player instead of /me/player/currently-playing to get full state including device
        $response = Http::withToken($this->accessToken)
            ->get($this->baseUri.'me/player');

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['item'])) {
                $albumImages = $data['item']['album']['images'] ?? [];

                return [
                    'uri' => $data['item']['uri'] ?? null,
                    'name' => $data['item']['name'],
                    'track' => $data['item']['name'],
                    'artist' => $data['item']['artists'][0]['name'] ?? 'Unknown',
                    'album' => $data['item']['album']['name'] ?? 'Unknown',
                    'album_art_url' => $albumImages[0]['url'] ?? null,
                    'progress_ms' => $data['progress_ms'] ?? 0,
                    'duration_ms' => $data['item']['duration_ms'] ?? 0,
                    'is_playing' => $data['is_playing'] ?? false,
                    'shuffle_state' => $data['shuffle_state'] ?? false,
                    'repeat_state' => $data['repeat_state'] ?? 'off',
                    'device' => $data['device'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Get multiple tracks by IDs (batch, max 50)
     */
    public function getTracks(array $trackIds): array
    {
        if ((! $this->accessToken && ! $this->refreshToken) || empty($trackIds)) {
            return [];
        }

        $this->ensureValidToken();

        $tracks = [];

        // Spotify allows max 50 IDs per request
        foreach (array_chunk($trackIds, 50) as $chunk) {
            $response = Http::withToken($this->accessToken)
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
                    if (preg_match('/music:musician_description"\s+content="([^"]+)"/', $html, $m)) {
                        $artist = $m[1];
                    }
                    if (preg_match('/og:description"\s+content="([^"]+)"/', $html, $m)) {
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
        $this->requireAuth();

        $profile = $this->getUserProfile();
        if (! $profile) {
            return null;
        }

        $response = Http::withToken($this->accessToken)
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
        $this->requireAuth();

        // Spotify allows max 100 URIs per request
        $first = true;
        foreach (array_chunk($trackUris, 100) as $chunk) {
            if ($first) {
                $response = Http::withToken($this->accessToken)
                    ->put($this->baseUri."playlists/{$playlistId}/tracks", [
                        'uris' => $chunk,
                    ]);
                $first = false;
            } else {
                $response = Http::withToken($this->accessToken)
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
        if (! $this->accessToken && ! $this->refreshToken) {
            return false;
        }

        $this->ensureValidToken();

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri."playlists/{$playlistId}", $details);

        return $response->successful();
    }

    /**
     * Get current user's profile (includes username/id)
     */
    public function getUserProfile(): ?array
    {
        $this->requireAuth();

        $response = Http::withToken($this->accessToken)
            ->get($this->baseUri.'me');

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }
}
