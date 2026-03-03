<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openrouter',

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered listening session agents.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Session Model Configuration
    |--------------------------------------------------------------------------
    |
    | Model assignments for AI-powered listening session roles. Each role
    | uses a specific model optimized for its task: parsing user intent,
    | curating track selections, and adapting to listener feedback.
    |
    */

    'session' => [
        'parser_model' => env('SESSION_PARSER_MODEL', 'anthropic/claude-haiku-4.5'),
        'curator_model' => env('SESSION_CURATOR_MODEL', 'x-ai/grok-3'),
        'adapt_model' => env('SESSION_ADAPT_MODEL', 'anthropic/claude-haiku-4.5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],
    ],

];
