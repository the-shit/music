<?php

use App\Providers\AppServiceProvider;
use App\Providers\McpServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Prism\Prism\PrismServiceProvider;

return [
    'name' => 'Spotify',
    'version' => 'v1.1.0',
    'env' => env('APP_ENV', 'production'),
    'providers' => [
        AppServiceProvider::class,
        McpServiceProvider::class,
        PrismServiceProvider::class,
        AiServiceProvider::class,
    ],
];
