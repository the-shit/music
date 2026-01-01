<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Spotify API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for THE SHIT Spotify integration
    |
    */

    'client_id' => env('SPOTIFY_CLIENT_ID'),
    'client_secret' => env('SPOTIFY_CLIENT_SECRET'),

    'redirect_uri' => env('SPOTIFY_REDIRECT_URI', 'http://127.0.0.1:8888/callback'),

    'scopes' => [
        'user-read-playback-state',
        'user-modify-playback-state',
        'user-read-currently-playing',
        'streaming',
        'playlist-read-private',
        'playlist-read-collaborative',
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

    'token_path' => env('SPOTIFY_TOKEN_PATH', ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/spotify-cli/token.json'),

    /*
    |--------------------------------------------------------------------------
    | Config Directory
    |--------------------------------------------------------------------------
    |
    | Base directory for all spotify-cli configuration files.
    |
    */

    'config_dir' => env('SPOTIFY_CONFIG_DIR', ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/spotify-cli'),
];
