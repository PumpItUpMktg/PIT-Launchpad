<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        // Relevance scoring is a cheap, high-volume pass — defaults to Haiku.
        'scoring_model' => env('ANTHROPIC_SCORING_MODEL', 'claude-haiku-4-5'),
        // Drafting is the quality-sensitive pass — defaults to Sonnet.
        'drafting_model' => env('ANTHROPIC_DRAFTING_MODEL', 'claude-sonnet-4-6'),
        // The §2 alt-text vision pass over rendered photos.
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
    ],

    // fal.ai image generation (§2 render pipeline). Hardened with an explicit
    // HTTP timeout so a render call can never hang.
    'fal' => [
        'key' => env('FAL_KEY'),
        'base_url' => env('FAL_BASE_URL', 'https://fal.run'),
        'model' => env('FAL_MODEL', 'fal-ai/flux/dev'),
        'timeout' => (int) env('FAL_TIMEOUT', 60),
    ],

    // Per-tenant R2 object storage for rendered images (served via CDN).
    'r2' => [
        'public_url' => env('R2_PUBLIC_URL'),
    ],

];
