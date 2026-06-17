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
        // Drafting writes a full HTML post + SEO JSON and runs extended thinking,
        // which spends from the same completion budget — 4096 let a long thinking
        // roll exhaust the budget before any text (stop_reason=max_tokens, empty
        // body). Give drafting a materially larger budget and cap thinking well
        // below it so reasoning can never starve the output.
        'drafting_max_tokens' => (int) env('ANTHROPIC_DRAFTING_MAX_TOKENS', 12000),
        'drafting_thinking_budget' => (int) env('ANTHROPIC_DRAFTING_THINKING_BUDGET', 4000),
        // The Phase-2 silo expansion emits a large dimensional JSON tree (SPG ≈ 40
        // spokes). Give it a generous budget so the tree can't truncate mid-JSON.
        'expander_max_tokens' => (int) env('ANTHROPIC_EXPANDER_MAX_TOKENS', 16000),
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

    // DataForSEO — SERP + keyword data (§5). `mode` selects the standard
    // (task-based, cheaper) vs live (synchronous) request mode.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        'mode' => env('DATAFORSEO_DEFAULT_MODE', 'standard'),
        'timeout' => (int) env('DATAFORSEO_TIMEOUT', 30),
        // Default geo for the location-less SerpProvider contract methods.
        'location_code' => (int) env('DATAFORSEO_LOCATION_CODE', 2840), // United States
        'language_code' => env('DATAFORSEO_LANGUAGE_CODE', 'en'),
        // Organic SERP depth — top-N occupants parsed for beatability.
        'serp_depth' => (int) env('DATAFORSEO_SERP_DEPTH', 20),
        // Related-keyword fetch limit.
        'related_limit' => (int) env('DATAFORSEO_RELATED_LIMIT', 20),
        // Local geo-grid around the market centre (NxN points at step degrees).
        'grid_size' => (int) env('DATAFORSEO_GRID_SIZE', 3),
        'grid_step' => (float) env('DATAFORSEO_GRID_STEP', 0.018),
        // Cache TTL (hours) guarding against re-fetch inside the refresh cadence.
        'cache_ttl_hours' => (int) env('DATAFORSEO_CACHE_TTL_HOURS', 168),
    ],

    // News feeds (§6a candidate funnel). `provider` selects the source: `gdelt`
    // (default, no key, ~3-month rolling window) or `newsapi` (keyed alternate,
    // paid in production). Non-secret tunables baked in.
    'news' => [
        'provider' => env('NEWS_PROVIDER', 'googlenews'),
        // Per-client recency window applied at the query level (default 90d).
        'recency_days' => (int) env('CONTENT_ENGINE_RECENCY_DAYS', 90),
        'timeout' => (int) env('NEWS_TIMEOUT', 30),
        // Google News RSS (default) — consent-aware fetch beats the datacenter-IP
        // wall GDELT hits. Locale via hl/gl/ceid; no key.
        'googlenews_base_url' => env('GOOGLE_NEWS_BASE_URL', 'https://news.google.com'),
        'googlenews_hl' => env('GOOGLE_NEWS_HL', 'en-US'),
        'googlenews_gl' => env('GOOGLE_NEWS_GL', 'US'),
        'googlenews_ceid' => env('GOOGLE_NEWS_CEID', 'US:en'),
        // GDELT DOC 2.0 — no auth. Throttle ~1 req / 5-6s; maxrecords caps at 250.
        'gdelt_base_url' => env('GDELT_BASE_URL', 'https://api.gdeltproject.org/api/v2/doc/doc'),
        'gdelt_throttle_seconds' => (int) env('GDELT_THROTTLE_SECONDS', 6),
        'gdelt_max_records' => (int) env('GDELT_MAX_RECORDS', 250),
        // NewsAPI — keyed alternate. NEWSAPI_KEY preferred; NEWS_API_KEY back-compat.
        'key' => env('NEWSAPI_KEY', env('NEWS_API_KEY')),
        'base_url' => env('NEWS_BASE_URL', 'https://newsapi.org/v2'),
    ],

    // OpenAI — embeddings for near-duplicate detection (§6a). `provider` selects
    // the embeddings backend. model + dimensions are pinned: every vector compared
    // against another must share both, so a change to either is a re-embed migration.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
        'provider' => env('EMBEDDINGS_PROVIDER', 'openai'),
    ],

    // Google — GSC (§5 calibration) + GA4 (§7c conversions) behind per-tenant
    // OAuth. The platform OAuth app creds are env (one app all clients consent
    // to); per-client access/refresh tokens live in the §9 vault, never here.
    // OAuth/API endpoints are non-secret defaults. Maps key is separate (location
    // pages), GBP is out (v1.5).
    'google' => [
        'project_id' => env('GOOGLE_PROJECT_ID'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'auth_uri' => env('GOOGLE_AUTH_URI', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_uri' => env('GOOGLE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'gsc_base_url' => env('GOOGLE_GSC_BASE_URL', 'https://www.googleapis.com/webmasters/v3'),
        'ga4_data_base_url' => env('GOOGLE_GA4_DATA_BASE_URL', 'https://analyticsdata.googleapis.com/v1beta'),
        'ga4_admin_base_url' => env('GOOGLE_GA4_ADMIN_BASE_URL', 'https://analyticsadmin.googleapis.com/v1beta'),
        'timeout' => (int) env('GOOGLE_TIMEOUT', 30),
    ],

    // US Census — demographics enrichment (§7a onboarding markets) + TIGERweb
    // service-area enumeration (Locations layer). TIGERweb is a public ArcGIS REST
    // service (no key). Layer ids are resolved by NAME at runtime; the configured ids
    // are only a fallback if that lookup fails (tigerWMS_Current: Places = 28,
    // County Subdivisions = 22). Keep the base URL on a current vintage.
    'census' => [
        'key' => env('CENSUS_API_KEY'),
        'tigerweb_url' => env('CENSUS_TIGERWEB_URL', 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer'),
        'tigerweb_places_layer' => (int) env('CENSUS_TIGERWEB_PLACES_LAYER', 28),
        'tigerweb_cousub_layer' => (int) env('CENSUS_TIGERWEB_COUSUB_LAYER', 22),
        'tigerweb_timeout' => (int) env('CENSUS_TIGERWEB_TIMEOUT', 30),
    ],

    // Krayin CRM — won-stage leads → conversions (self-hosted, shared instance;
    // deferred until deployed). `won_stages` are the pipeline stages counted.
    'krayin' => [
        'base_url' => env('KRAYIN_BASE_URL'),
        'token' => env('KRAYIN_API_TOKEN', env('KRAYIN_TOKEN')),
        'won_stages' => array_values(array_filter(array_map('trim', explode(',', (string) env('KRAYIN_WON_STAGES', 'won'))))),
        'timeout' => (int) env('KRAYIN_TIMEOUT', 30),
    ],

    // Mautic — form submissions → conversions (self-hosted, shared instance;
    // deferred until deployed). `conversion_form_id` is the lead-gen form pulled.
    'mautic' => [
        'base_url' => env('MAUTIC_BASE_URL'),
        'client_id' => env('MAUTIC_CLIENT_ID'),
        'client_secret' => env('MAUTIC_CLIENT_SECRET'),
        'conversion_form_id' => env('MAUTIC_CONVERSION_FORM_ID'),
        'timeout' => (int) env('MAUTIC_TIMEOUT', 30),
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
