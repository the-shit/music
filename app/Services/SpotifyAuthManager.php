&lt;?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class SpotifyAuthManager
{
    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    private ?int $expiresAt = null;

    private ?string $clientId;

    private ?string $clientSecret;

    private string $tokenFile;

    public function __construct()
    {
        $this-&gt;clientId = config(&#x27;spotify.client_id&#x27;, &#x27;&#x27;);
        $this-&gt;clientSecret = config(&#x27;spotify.client_secret&#x27;, &#x27;&#x27;);

        // Use config path for PHAR compatibility (base_path() doesn&#x27;t work in PHAR)
        $this-&gt;tokenFile = config(&#x27;spotify.token_path&#x27;);

        $this-&gt;loadTokenData();
    }

    /**
     * Check if Spotify is properly configured
     */
    public function isConfigured(): bool
    {
        return ! empty($this-&gt;clientId) &amp;&amp; ! empty($this-&gt;clientSecret)
            &amp;&amp; (! empty($this-&gt;accessToken) || ! empty($this-&gt;refreshToken));
    }

    /**
     * Load token data from storage
     */
    private function loadTokenData(): void
    {
        if (file_exists($this-&gt;tokenFile)) {
            $data = json_decode(file_get_contents($this-&gt;tokenFile), true);
            if ($data) {
                $this-&gt;accessToken = $data[&#x27;access_token&#x27;] ?? null;
                $this-&gt;refreshToken = $data[&#x27;refresh_token&#x27;] ?? null;
                $this-&gt;expiresAt = $data[&#x27;expires_at&#x27;] ?? null;
            }
        }
    }

    /**
     * Re-read credentials and token data fresh from disk, bypassing config cache.
     * Picks up tokens saved by external processes (e.g. `spotify login`).
     */
    private function reloadFromDisk(): void
    {
        $configDir = dirname($this-&gt;tokenFile);
        $credentialsFile = $configDir.&#x27;/credentials.json&#x27;;

        if (file_exists($credentialsFile)) {
            $creds = json_decode(file_get_contents($credentialsFile), true);
            if ($creds) {
                $this-&gt;clientId = $creds[&#x27;client_id&#x27;] ?? $this-&gt;clientId;
                $this-&gt;clientSecret = $creds[&#x27;client_secret&#x27;] ?? $this-&gt;clientSecret;
            }
        }

        $this-&gt;loadTokenData();
    }

    /**
     * Check if token is expired and refresh if needed
     */
    private function ensureValidToken(): void
    {
        // If we have a refresh token and the access token is expired (or about to expire in 60 seconds)
        if ($this-&gt;refreshToken &amp;&amp; (! $this-&gt;expiresAt || $this-&gt;expiresAt &lt; (time() + 60))) {
            // Re-read from disk first — an external `spotify login` may have saved fresh tokens
            $this-&gt;reloadFromDisk();

            // After reload, check if we still need to refresh
            if (! $this-&gt;expiresAt || $this-&gt;expiresAt &lt; (time() + 60)) {
                $this-&gt;refreshAccessToken();
            }

            if (! $this-&gt;accessToken) {
                throw new \Exception(&#x27;Session expired. Run &quot;spotify login&quot; to re-authenticate.&#x27;);
            }
        }
    }

    /**
     * Ensure we have a valid access token, throwing if not authenticated.
     * Combines the null-check + refresh into one call.
     */
    public function requireAuth(): void
    {
        if (! $this-&gt;accessToken &amp;&amp; ! $this-&gt;refreshToken) {
            throw new \Exception(&#x27;Not authenticated. Run &quot;spotify login&quot; first.&#x27;);
        }

        $this-&gt;ensureValidToken();

        if (! $this-&gt;accessToken) {
            throw new \Exception(&#x27;Not authenticated. Run &quot;spotify login&quot; first.&#x27;);
        }
    }

    /**
     * Refresh the access token using refresh token
     */
    private function refreshAccessToken(): void
    {
        // Retry refresh up to 3 times — transient failures shouldn&#x27;t nuke the session
        for ($attempt = 1; $attempt &lt;= 3; $attempt++) {
            $response = Http::withHeaders([
                &#x27;Authorization&#x27; =&gt; &#x27;Basic &#x27;.base64_encode($this-&gt;clientId.&#x27;:&#x27;.$this-&gt;clientSecret),
            ])-&gt;asForm()-&gt;post(&#x27;https://accounts.spotify.com/api/token&#x27;, [
                &#x27;grant_type&#x27; =&gt; &#x27;refresh_token&#x27;,
                &#x27;refresh_token&#x27; =&gt; $this-&gt;refreshToken,
            ]);

            if ($response-&gt;successful()) {
                $data = $response-&gt;json();
                $this-&gt;accessToken = $data[&#x27;access_token&#x27;];
                $this-&gt;expiresAt = time() + ($data[&#x27;expires_in&#x27;] ?? 3600);

                // Spotify may rotate refresh tokens — always capture the new one
                if (! empty($data[&#x27;refresh_token&#x27;])) {
                    $this-&gt;refreshToken = $data[&#x27;refresh_token&#x27;];
                }

                // Save updated token data
                $this-&gt;saveTokenData();

                return;
            }

            // Only clear token on 4xx (truly revoked), not on network/5xx errors
            if ($response-&gt;status() &gt;= 400 &amp;&amp; $response-&gt;status() &lt; 500) {
                $this-&gt;accessToken = null;
                $this-&gt;expiresAt = null;
                $this-&gt;saveTokenData();

                return;
            }

            if ($attempt &lt; 3) {
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
            &#x27;access_token&#x27; =&gt; $this-&gt;accessToken,
            &#x27;refresh_token&#x27; =&gt; $this-&gt;refreshToken,
            &#x27;expires_at&#x27; =&gt; $this-&gt;expiresAt,
        ];

        // Ensure config directory exists (PHAR compatible path)
        $configDir = dirname($this-&gt;tokenFile);
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        // Save with restricted permissions
        file_put_contents($this-&gt;tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));
        chmod($this-&gt;tokenFile, 0600); // Only owner can read/write
    }

    public function getAccessToken(): ?string
    {
        $this-&gt;ensureValidToken();
        return $this-&gt;accessToken;
    }
}
