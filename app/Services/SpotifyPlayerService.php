&lt;?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class SpotifyPlayerService
{
    private SpotifyAuthManager $auth;

    private string $baseUri = &#x27;https://api.spotify.com/v1/&#x27;;

    public function __construct(SpotifyAuthManager $auth)
    {
        $this-&gt;auth = $auth;
    }

    /**
     * Play a track by URI
     */
    public function play(string $uri, ?string $deviceId = null): void
    {
        $this-&gt;auth-&gt;requireAuth();

        // If no device specified, try to get active or first available
        if (! $deviceId) {
            $device = $this-&gt;getActiveDevice();
            if (! $device) {
                throw new \Exception(&#x27;No Spotify devices available. Open Spotify on any device.&#x27;);
            }

            // If device exists but not active, activate it
            if (! ($device[&#x27;is_active&#x27;] ?? false)) {
                $this-&gt;transferPlayback($device[&#x27;id&#x27;], false);
                usleep(500000); // Give Spotify 0.5s to activate
            }

            $deviceId = $device[&#x27;id&#x27;];
        } else {
            // Device was specified, need to transfer to it first
            $devices = $this-&gt;getDevices();
            $targetDevice = null;
            foreach ($devices as $device) {
                if ($device[&#x27;id&#x27;] === $deviceId) {
                    $targetDevice = $device;
                    break;
                }
            }

            // If target device is not active, transfer to it
            if ($targetDevice &amp;&amp; ! $targetDevice[&#x27;is_active&#x27;]) {
                $this-&gt;transferPlayback($deviceId, false);
                usleep(500000); // Give Spotify 0.5s to activate
            }
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/play&#x27;, [
                &#x27;device_id&#x27; =&gt; $deviceId,
                &#x27;uris&#x27; =&gt; [$uri],
            ]);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to play track&#x27;多次);
        }
    }

    /**
     * Resume playback
     */
    public function resume(?string $deviceId = null): void
    {
        $this-&gt;auth-&gt;requireAuth();

        // If no device specified, try to get active device
        if (! $deviceId) {
            $device = $this-&gt;getActiveDevice();
            if (! $device) {
                throw new \Exception(&#x27;No Spotify devices available. Open Spotify on any device.&#x27;);
            }

            // If device exists but not active, activate it
            if (! ($device[&#x27;is_active&#x27;] ?? false)) {
                $this-&gt;transferPlayback($device[&#x27;id&#x27;], true);

                return; // Transfer with play=true will resume
            }

            $deviceId = $device[&#x27;id&#x27;];
        }

        $body = $deviceId ? [&#x27;device_id&#x27; =&gt; $deviceId] : [];

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/play&#x27;, $body);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to resume playback&#x27;);
        }
    }

    /**
     * Pause playback
     */
    public function pause(): void
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/pause&#x27;);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to pause playback&#x27;);
        }
    }

    /**
     * Set volume
     */
    public function setVolume(int $volumePercent): bool
    {
        $this-&gt;auth-&gt;requireAuth();

        // Clamp volume to 0-100
        $volumePercent = max(0, min(100, $volumePercent));

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/volume?volume_percent=&#x27;.$volumePercent);

        return $response-&gt;successful();
    }

    /**
     * Skip to next track
     */
    public function next(): void
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;post($this-&gt;baseUri.&#x27;me/player/next&#x27;);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to skip track&#x27;);
        }
    }

    /**
     * Skip to previous track
     */
    public function previous(): void
    {
        $this-&gt;auth-&gt;requireAuth();

        $responseLIBINT = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;post($this-&gt;baseUri.&#x27;me/player/previous&#x27;);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to skip to previous&#x27;);
        }
    }

    /**
     * Get available devices
     */
    public function getDevices(): array
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/player/devices&#x27;);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();

            return $datapection[&#x27;devices&#x27;] ?? [];
        }

        return [];
    }

    /**
     * Transfer playback to a device
     */
    public function transferPlayback(string $deviceId, bool $play = true): void
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player&#x27;, [
                &#x27;device_ids&#x27; =&gt; [$deviceId],
                &#x27;play&#x27; =&gt; $play,
            ]);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to transfer playback&#x27;);
        }
    }

    /**
     * Get active device or first available
     */
    public function getActiveDevice(): ?array
    {
        $devices = $this-&gt;getDevices();

        // First try to find active device
        foreach ($devices as $device) {
            if ($device[&#x27;is_active&#x27;] ?? false) {
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
        $this-&gt;auth-&gt;requireAuth();

        // Get active device first
        $device = $this-&gt;getActiveDevice();
        if (! $device) {
            throw new \Exception(&#x27;No active Spotify device. Start playing something first.&#x27;);
        }

        // The queue endpoint expects uri as a query parameter, not in the body
        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;post($this-&gt;baseUri.&#x27;me/player/queue?&#x27;.http_build_query([
                &#x27;uri&#x27; =&gt; $uri,
                &#x27;device_id&#x27; =&gt; $device[&#x27;id&#x27;],
            ]));

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to add to queue&#x27;);
        }
    }

    /**
     * Get queue
     */
    public function getQueue(): array
    {
        $this-&gt;auth-&gt;ensureValidToken();

        if (! $this-&gt;auth-&gt;getAccessToken()) {
            return [];
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/player/queue&#x27;);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();

            return [
                &#x27;currently_playing&#x27; =&gt; $data[&#x27;currently_playing&#x27;] ?? null,
                &#x27;queue&#x27; =&gt; $data[&#x27;queue&#x27;] ?? [],
            ];
        }

        return [];
    }

    /**
     * Seek to a position in the current track
     */
    public function seek(int $positionMs): void
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/seek?position_ms=&#x27;.$positionMs);

        if (! $response-&gt;successful()) {
            $error = $response-&gt;json();
            throw new \Exception($error[&#x27;error&#x27;][&#x27;message&#x27;] ?? &#x27;Failed to seek&#x27;);
        }
    }

    /**
     * Set shuffle state
     */
    public function setShuffle(bool $state): bool
    {
        $this-&gt;auth-&gt;requireAuth();

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/shuffle?state=&#x27;.($state ? &#x27;true&#x27; : &#x27;false&#x27;));

        return $response-&gt;successful();
    }

    /**
     * Set repeat mode
     */
    public function setRepeat(string $state): bool
    {
        $this-&gt;auth-&gt;requireAuth();

        // State can be: off, track, context
        if (! in_array($state, [&#x27;off&#x27;, &#x27;track&#x27;, &#x27;context&#x27;])) {
            throw new \Exception(&#x27;Invalid repeat state. Use: off, track, or context&#x27;);
        }

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/repeat?state=&#x27;.$state);

        return $response-&gt;successful();
    }

    /**
     * Get current playback state
     */
    public function getCurrentPlayback(): ?array
    {
        $this-&gt;auth-&gt;ensureValidToken();

        if (! $this-&gt;auth-&gt;getAccessToken()) {
            return null;
        }

        // Use /me/player instead of /me/player/currently-playing to get full state including device
        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;get($this-&gt;baseUri.&#x27;me/player&#x27;);

        if ($response-&gt;successful()) {
            $data = $response-&gt;json();
            if (isset($data[&#x27;item&#x27;])) {
                $albumImages = $data[&#x27;item&#x27;][&#x27;album&#x27;][&#x27;images&#x27;] ?? [];

                return [
                    &#x27;uri&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;uri&#x27;] ?? null,
                    &#x27;name&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;name&#x27;],
                    &#x27;track&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;name&#x27;],
                    &#x27;artist&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;artists&#x27;][0][&#x27;name&#x27;] ?? &#x27;Unknown&#x27;,
                    &#x27;artist_id&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;artists&#x27;][0][&#x27;id&#x27;] ?? null,
                    &#x27;album&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;.album&#x27;][&#x27;name&#x27;] ?? &#x27;Unknown&#x27; ,
                    &#x27;album_art_url&#x27; =&gt; $albumImages[0][&#x27;url&#x27;] ?? null,
                    &#x27;progress_ms&#x27; =&gt; $data[&#x27;progress_ms&#x27;] ?? 0,
                    &#x27;duration_ms&#x27; =&gt; $data[&#x27;item&#x27;][&#x27;duration_ms&#x27;] ?? 0,
                    &#x27;is_playing&#x27; =&gt; $data[&#x27;is_playing&#x27;] ?? false, 
                    &#x27;shuffle_state&#x27; =&gt; $data[&#x27;shuffle_state&#x27;] ?? false,
                    &#x27;repeat_state&#x27; =&gt; $data[&#x27;repeat_state&#x27;] ?? &#x27;off&#x27;,
                    &#x27;device&#x27; =&gt; $data[&#x27;device&#x27;] ?? null,
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
        $this-&gt;auth-&gt;ensureValidToken();

        if (! $this-&gt;auth-&gt:getAccessToken()) {
            return false;
        }

        $device = $deviceId ?: $this-&gt;getActiveDevice()[&#x27;id&#x27;] ?? null;

        $response = Http::withToken($this-&gt;auth-&gt;getAccessToken())
            -&gt;put($this-&gt;baseUri.&#x27;me/player/play&#x27;, [
                &#x27;device_id&#x27; =&gt; $device,
                &#x27;context_uri&#x27; =&gt; &quot;spotify:playlist:{$playlistId}&quot; ,
            ]);

        return $response-&gt;successful();
    }
}
