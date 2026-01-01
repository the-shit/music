<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SpotifyService
{
    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    private ?int $expiresAt = null;

    private string $clientId;

    private string $clientSecret;

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
        // Try current config location first
        if (file_exists($this->tokenFile)) {
            $data = json_decode(file_get_contents($this->tokenFile), true);
            if ($data) {
                $this->accessToken = $data['access_token'] ?? null;
                $this->refreshToken = $data['refresh_token'] ?? null;
                $this->expiresAt = $data['expires_at'] ?? null;

                return;
            }
        }

        // Fall back to legacy locations for migration
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $legacyLocations = [
            $home.'/.spotify_token',
            base_path('storage/spotify_token.json'),
        ];

        foreach ($legacyLocations as $oldTokenFile) {
            if (file_exists($oldTokenFile)) {
                $content = file_get_contents($oldTokenFile);

                // Handle old format (just token string)
                if (! json_decode($content)) {
                    $this->accessToken = trim($content);
                } else {
                    // Handle new format (JSON with refresh token)
                    $data = json_decode($content, true);
                    $this->accessToken = $data['access_token'] ?? null;
                    $this->refreshToken = $data['refresh_token'] ?? null;
                    $this->expiresAt = $data['expires_at'] ?? null;
                }

                // Migrate to new location
                $this->saveTokenData();

                // Remove old file after migration
                @unlink($oldTokenFile);

                return;
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
        }
    }

    /**
     * Refresh the access token using refresh token
     */
    private function refreshAccessToken(): void
    {
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

            // Save updated token data
            $this->saveTokenData();
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
     * Save access token to storage (legacy compatibility)
     */
    private function saveAccessToken(string $token): void
    {
        $this->accessToken = $token;
        $this->saveTokenData();
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
                return [
                    'name' => $data['item']['name'],
                    'artist' => $data['item']['artists'][0]['name'] ?? 'Unknown',
                    'album' => $data['item']['album']['name'] ?? 'Unknown',
                    'progress_ms' => $data['progress_ms'] ?? 0,
                    'duration_ms' => $data['item']['duration_ms'] ?? 0,
                    'is_playing' => $data['is_playing'] ?? false,
                    'shuffle_state' => $data['shuffle_state'] ?? false,  // Include shuffle state
                    'repeat_state' => $data['repeat_state'] ?? 'off',  // Include repeat state
                    'device' => $data['device'] ?? null,  // Include device info
                ];
            }
        }

        return null;
    }
}
