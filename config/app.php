<?php

return [
    'name' => 'Spotify',
    'version' => 'v1.1.0',
    'env' => env('APP_ENV', 'production'),
    'providers' => [
        App\Providers\AppServiceProvider::class,
        App\Providers\McpServiceProvider::class,
        Prism\Prism\PrismServiceProvider::class,
        Laravel\Ai\AiServiceProvider::class,
    ],
];
