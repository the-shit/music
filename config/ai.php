<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered listening session agents.
    |
    */

    'session' => [
        'adapt_model' => env('AI_SESSION_ADAPT_MODEL', 'claude-haiku-4-5-20251001'),
    ],

];
