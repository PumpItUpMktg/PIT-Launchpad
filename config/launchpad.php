<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credential rotation
    |--------------------------------------------------------------------------
    |
    | Staleness thresholds (in days) per Connection provider. The scheduled
    | staleness check flags credentials whose last_rotated_at exceeds the
    | threshold for the admin connections panel. This is advisory only — the
    | pre-client launch gate is the hard requirement, and nothing here ever
    | auto-rotates a credential.
    |
    */

    'rotation' => [
        'staleness_days' => [
            'wp_app_password' => env('LAUNCHPAD_STALE_WP_DAYS', 90),
            'gbp' => env('LAUNCHPAD_STALE_GBP_DAYS', 180),
            'ga4' => env('LAUNCHPAD_STALE_GA4_DAYS', 180),
            'ghl' => env('LAUNCHPAD_STALE_GHL_DAYS', 180),
        ],

        'default_staleness_days' => env('LAUNCHPAD_STALE_DEFAULT_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | §6a feeds (client-managed + generated)
    |--------------------------------------------------------------------------
    |
    | client_soft_cap is the generous per-site limit on client-added direct
    | feeds (advisory friction, not a hard wall). unhealthy_after_days is how
    | long a feed can go without yielding an item before the panel flags it
    | unhealthy. The generated.* locale builds the Google News RSS search URL
    | the reconcile job materializes — it mirrors the GOOGLE_NEWS_* provider
    | config so generated feeds and the §6a default source stay in lockstep.
    |
    */

    'feeds' => [
        'client_soft_cap' => (int) env('LAUNCHPAD_CLIENT_FEED_CAP', 25),
        'unhealthy_after_days' => (int) env('LAUNCHPAD_FEED_UNHEALTHY_DAYS', 21),
        'fetch_timeout' => (int) env('LAUNCHPAD_FEED_TIMEOUT', 30),
        'fetch_max_items' => (int) env('LAUNCHPAD_FEED_MAX_ITEMS', 100),

        'generated' => [
            'base_url' => env('GOOGLE_NEWS_BASE_URL', 'https://news.google.com'),
            'hl' => env('GOOGLE_NEWS_HL', 'en-US'),
            'gl' => env('GOOGLE_NEWS_GL', 'US'),
            'ceid' => env('GOOGLE_NEWS_CEID', 'US:en'),
        ],
    ],

];
