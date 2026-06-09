<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Credentials and connection settings for third-party services. Secret values
    | are read from the environment and left blank in .env.example; non-secret
    | defaults (base URLs, model strings, provider/mode flags) are baked in here.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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

    // Anthropic / Claude — drafting (§6b), relevance scoring (§6a), and the §2
    // alt-text vision pass. Model strings are non-secret defaults.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        'scoring_model' => env('ANTHROPIC_SCORING_MODEL', 'claude-haiku-4-5'),
        'drafting_model' => env('ANTHROPIC_DRAFTING_MODEL', 'claude-sonnet-4-6'),
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
    ],

    // fal.ai image generation (§2 render pipeline). `provider` is the selected
    // content-engine image provider; the render call has an explicit HTTP timeout.
    'fal' => [
        'key' => env('FAL_KEY'),
        'base_url' => env('FAL_BASE_URL', 'https://fal.run'),
        'image_model' => env('FAL_IMAGE_MODEL', 'fal-ai/flux/dev'),
        'provider' => env('CONTENT_ENGINE_IMAGE_PROVIDER', 'fal'),
        'timeout' => (int) env('FAL_TIMEOUT', 60),
    ],

    // DataForSEO — SERP + keyword data (§5). `mode` selects the standard vs live
    // request mode.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        'mode' => env('DATAFORSEO_DEFAULT_MODE', 'standard'),
    ],

    // News feeds (§6a candidate funnel). `provider` selects the news source.
    'news' => [
        'provider' => env('NEWS_PROVIDER', 'newsapi'),
        'key' => env('NEWS_API_KEY'),
        'base_url' => env('NEWS_BASE_URL', 'https://newsapi.org/v2'),
    ],

    // OpenAI — embeddings for near-duplicate detection (§6a). `provider` selects
    // the embeddings backend.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'provider' => env('EMBEDDINGS_PROVIDER', 'openai'),
    ],

    // Google — GBP OAuth, Maps, and the project for analytics (§5/§7).
    'google' => [
        'project_id' => env('GOOGLE_PROJECT_ID'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    // US Census — demographics enrichment (§7a onboarding markets).
    'census' => [
        'key' => env('CENSUS_API_KEY'),
    ],

    // Krayin CRM — lead sync (self-hosted; base URL is per-install).
    'krayin' => [
        'base_url' => env('KRAYIN_BASE_URL'),
        'token' => env('KRAYIN_TOKEN'),
    ],

    // Mautic — marketing automation (self-hosted; base URL is per-install).
    'mautic' => [
        'base_url' => env('MAUTIC_BASE_URL'),
        'client_id' => env('MAUTIC_CLIENT_ID'),
        'client_secret' => env('MAUTIC_CLIENT_SECRET'),
    ],

    // Cal.com — scheduling.
    'calcom' => [
        'base_url' => env('CALCOM_BASE_URL', 'https://api.cal.com/v2'),
        'key' => env('CALCOM_API_KEY'),
    ],

    // Flowroute — telephony / SMS.
    'flowroute' => [
        'access_key' => env('FLOWROUTE_ACCESS_KEY'),
        'secret_key' => env('FLOWROUTE_SECRET_KEY'),
        'base_url' => env('FLOWROUTE_BASE_URL', 'https://api.flowroute.com/v2'),
    ],

    // Cloudflare R2 object storage public/CDN base (§2). The disk itself is in
    // config/filesystems.php.
    'r2' => [
        'public_url' => env('R2_PUBLIC_URL'),
    ],

];
