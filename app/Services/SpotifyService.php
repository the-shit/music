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
        return ! empty($this->clientId) && ! empty($this->clientSecret) && ! empty($this->accessToken);
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
     * Check if token is expired and refresh if needed
     */
    private function ensureValidToken(): void
    {
        // If we have a refresh token and the access token is expired (or about to expire in 60 seconds)
        if ($this->refreshToken && (! $this->expiresAt || $this->expiresAt < (time() + 60))) {
            $this->refreshAccessToken();

            if (! $this->accessToken) {
                throw new \Exception('Session expired. Run "spotify login" to re-authenticate.');
            }
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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
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
        if (! $this->accessToken) {
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
        if (! $this->accessToken) {
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
        if (! $this->accessToken) {
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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
     * Get track recommendations from Spotify's algorithm
     */
    public function getRecommendations(array $seedTrackIds = [], array $seedArtistIds = [], int $limit = 10): array
    {
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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

            return $tracks;
        }

        return [];
    }

    /**
     * Get related tracks using search as a fallback for the deprecated recommendations API.
     *
     * Searches for more tracks by the same artist and shuffles
     * the results to add variety.
     */
    public function getRelatedTracks(string $artistName, string $trackName, int $limit = 10): array
    {
        // Search for more tracks by the same artist (excluding the current track)
        $artistTracks = $this->searchMultiple("artist:\"{$artistName}\"", 'track', $limit + 5);

        // Also search with a looser query to get related-sounding tracks
        $relatedTracks = $this->searchMultiple("\"{$artistName}\"", 'track', $limit);

        // Merge and deduplicate by URI
        $seen = [];
        $merged = [];

        foreach (array_merge($artistTracks, $relatedTracks) as $track) {
            $uri = $track['uri'];
            if (isset($seen[$uri])) {
                continue;
            }
            $seen[$uri] = true;
            $merged[] = $track;
        }

        // Shuffle to avoid always returning the same top results
        shuffle($merged);

        return array_slice($merged, 0, $limit);
    }

    /**
     * Set shuffle state
     */
    public function setShuffle(bool $state): bool
    {
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

        $response = Http::withToken($this->accessToken)
            ->put($this->baseUri.'me/player/shuffle?state='.($state ? 'true' : 'false'));

        return $response->successful();
    }

    /**
     * Set repeat mode
     */
    public function setRepeat(string $state): bool
    {
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
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
        if (! $this->accessToken || empty($trackIds)) {
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
     * Create a playlist for the authenticated user
     */
    public function createPlaylist(string $name, string $description = '', bool $public = true): ?array
    {
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

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
        if (! $this->accessToken) {
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
        if (! $this->accessToken) {
            throw new \Exception('Not authenticated. Run "music login" first.');
        }

        $this->ensureValidToken();

        $response = Http::withToken($this->accessToken)
            ->get($this->baseUri.'me');

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }
}
