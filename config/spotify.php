<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Spotify API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Spotify CLI integration
    |
    */

    // Support reading from user config directory for PHAR compatibility
    'config_dir' => $_SERVER['HOME'].'/.config/spotify-cli',

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

    // Token path in user config directory (PHAR-compatible)
    'token_path' => $_SERVER['HOME'].'/.config/spotify-cli/token.json',

    // Credentials path in user config directory (PHAR-compatible)
    'credentials_path' => $_SERVER['HOME'].'/.config/spotify-cli/credentials.json',
];
