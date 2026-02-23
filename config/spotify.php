<?php

// Load credentials from file if not in env
$configDir = ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/spotify-cli';
$credentialsFile = $configDir.'/credentials.json';
$credentials = [];
if (file_exists($credentialsFile)) {
    $credentials = json_decode(file_get_contents($credentialsFile), true) ?? [];
}

return [
    /*
    |--------------------------------------------------------------------------
    | Spotify API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for THE SHIT Spotify integration
    |
    */

    'client_id' => env('SPOTIFY_CLIENT_ID', $credentials['client_id'] ?? null),
    'client_secret' => env('SPOTIFY_CLIENT_SECRET', $credentials['client_secret'] ?? null),

    'redirect_uri' => env('SPOTIFY_REDIRECT_URI', 'http://127.0.0.1:8888/callback'),

    'scopes' => [
        'user-read-playback-state',
        'user-modify-playback-state',
        'user-read-currently-playing',
        'streaming',
        'playlist-read-private',
        'playlist-read-collaborative',
        'playlist-modify-public',
        'playlist-modify-private',
        'user-top-read',
        'user-read-recently-played',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Storage Path
    |--------------------------------------------------------------------------
    |
    | Path to store OAuth tokens. Uses ~/.config/spotify-cli/ for PHAR
    | compatibility (base_path() doesn't work in PHAR archives).
    |
    */

    'token_path' => env('SPOTIFY_TOKEN_PATH', $configDir.'/token.json'),

    /*
    |--------------------------------------------------------------------------
    | Config Directory
    |--------------------------------------------------------------------------
    |
    | Base directory for all spotify-cli configuration files.
    |
    */

    'config_dir' => env('SPOTIFY_CONFIG_DIR', $configDir),
];
