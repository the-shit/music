<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class SpotifyPlayerService
{
    private SpotifyAuthManager $auth;

    private string $baseUri = 'https://api.spotify.com/v1/';

    public function __construct(SpotifyAuthManager $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Play a track by URI
     */
    public function play(string $uri, ?string $deviceId = null): void
    {
        $this->auth->requireAuth();

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

        $response = Http::withToken($this->auth->getAccessToken())
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
        $this->auth->requireAuth();

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

        $response = Http::withToken($this->auth->getAccessToken())
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
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
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
        $this->auth->requireAuth();

        // Clamp volume to 0-100
        $volumePercent = max(0, min(100, $volumePercent));

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri.'me/player/volume?volume_percent='.$volumePercent);

        return $response->successful();
    }

    /**
     * Skip to next track
     */
    public function next(): void
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
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
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
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
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
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
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri.'me/player', [
                'device_ids' => [$deviceId],
                'play' => $play,
            ]);

        if (! $response->successful()) {
            $error = $response->json();
            throw new Exception($error['error']['message'] ?? 'Failed to transfer playback');
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
        $this->auth->requireAuth();

        // Get active device first
        $device = $this->getActiveDevice();
        if (! $device) {
            throw new \Exception('No active Spotify device. Start playing something first.');
        }

        // The queue endpoint expects uri as a query parameter, not in the body
        $response = Http::withToken($this->auth->getAccessToken())
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
     * Get queue
     */
    public function getQueue(): array
    {
        if (! $this->auth->getAccessToken()) {
            return [];
        }

        $response = Http::withToken($this->auth->getAccessToken())
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
     * Seek to a position in the current track
     */
    public function seek(int $positionMs): void
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri.'me/player/seek?position_ms='.$positionMs);

        if (! $response->successful()) {
            $error = $response->json();
            throw new \Exception($error['error']['message'] ?? 'Failed to seek');
        }
    }

    /**
     * Set shuffle state
     */
    public function setShuffle(bool $state): bool
    {
        $this->auth->requireAuth();

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri.'me/player/shuffle?state='.($state ? 'true' : 'false'));

        return $response->successful();
    }

    /**
     * Set repeat mode
     */
    public function setRepeat(string $state): bool
    {
        $this->auth->requireAuth();

        // State can be: off, track, context
        if (! in_array($state, ['off', 'track', 'context'])) {
            throw new \Exception('Invalid repeat state. Use: off, track, or context');
        }

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri.'me/player/repeat?state='.$state);

        return $response->successful();
    }

    /**
     * Get current playback state
     */
    public function getCurrentPlayback(): ?array
    {
        if (! $this->auth->getAccessToken()) {
            return null;
        }

        // Use /me/player instead of /me/player/currently-playing to get full state including device
        $response = Http::withToken($this->auth->getAccessToken())
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
                    'artist_id' => $data['item']['artists'][0]['id'] ?? null,
                    'album' => $data['item']['.album']['name'] ?? 'Unknown' ,
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
     * Play a playlist
     */
    public function playPlaylist(string $playlistId, ?string $deviceId = null): bool
    {
        if (! $this->auth->getAccessToken()) {
            return false;
        }

        $device = $deviceId ?: $this->getActiveDevice()['id'] ?? null;

        $response = Http::withToken($this->auth->getAccessToken())
            ->put($this->baseUri.'me/player/play', [
                'device_id' => $device,
                'context_uri' => "spotify:playlist:{$playlistId}" ,
            ]);

        return $response->successful();
    }
}
