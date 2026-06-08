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

];
