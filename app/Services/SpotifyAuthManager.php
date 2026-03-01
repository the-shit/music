<?php

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
    public function requireAuth(): void
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

    public function getAccessToken(): ?string
    {
        $this->ensureValidToken();
        return $this->accessToken;
    }
}
