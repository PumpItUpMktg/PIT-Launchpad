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

    /*
    |--------------------------------------------------------------------------
    | Brand generation (C5 — brand intake → Elementor Global Kit)
    |--------------------------------------------------------------------------
    |
    | The AI brand generator (BrandGenerator) returns a palette + typography that
    | is then pushed into the tenant's Elementor Global Kit. Every returned font
    | family is validated against the real loadable Google Fonts catalog; any
    | miss/hallucination falls back to a safe default below, so an invented or
    | misspelled family can never silently break the cascade. The text color is
    | also held to a WCAG-AA contrast floor against a light background.
    |
    */

    'brand' => [
        'safe_fonts' => [
            'heading' => env('LAUNCHPAD_BRAND_SAFE_HEADING_FONT', 'Poppins'),
            'body' => env('LAUNCHPAD_BRAND_SAFE_BODY_FONT', 'Inter'),
        ],
        // The full safe palette (Phase 3): every brand-token slot has a known-good,
        // AA-passing default the generator falls back to per-slot. The wf-* stylesheet
        // mirrors these as its own fallbacks. (The surface slots here are the LIGHT
        // scheme; per-scheme surfaces live in `scheme_surfaces` below.)
        'safe_colors' => [
            'primary' => '#0F62FE',
            'secondary' => '#3E6E9E',
            'accent' => '#FF6F00',
            'text' => '#1A1A1A',
            'text_muted' => '#5B6470',
            'bg' => '#FFFFFF',
            'bg_alt' => '#F4F6F8',
            'border' => '#E2E6EB',
        ],

        // Per-SCHEME surface safe defaults (the two-axis model): the generator
        // conforms a candidate's surfaces to the chosen scheme and falls back to these
        // per slot. Light = dark text on light bg; Dark = light text on dark bg. Brand
        // hues (primary/secondary/accent) are scheme-independent (safe_colors above).
        'scheme_surfaces' => [
            'light' => [
                'bg' => '#FFFFFF',
                'bg_alt' => '#F4F6F8',
                'text' => '#1A1A1A',
                'text_muted' => '#5B6470',
                'border' => '#E2E6EB',
            ],
            'dark' => [
                'bg' => '#0F172A',
                'bg_alt' => '#1E293B',
                'text' => '#F1F5F9',
                'text_muted' => '#94A3B8',
                'border' => '#334155',
            ],
        ],

        // Minimum WCAG contrast ratio for the text color against a light bg.
        'min_text_contrast' => 4.5,

        // Phase 3 — multi-candidate generator.
        //
        // Deterministic personality → structure map: the AI recommends a structure,
        // but an off-list answer falls back through this (the enforcer behind the
        // proposer). Keys are BrandBrief::PERSONALITIES.
        'structure_for_personality' => [
            'trustworthy' => 'trust',
            'modern-technical' => 'bold',
            'friendly-local' => 'warm',
            'premium' => 'trust',
            'bold-urgent' => 'bold',
        ],
        'default_structure' => 'trust',

        // The curated heading/body PAIRINGS the generator is STEERED to, per
        // structure (the model picks one pairing per candidate, varying across the
        // set). Generation is constrained to these in-prompt; every returned family
        // is still validated against the full FontCatalog. [operator-redlined]
        'font_pairings' => [
            'trust' => [
                ['heading' => 'Inter', 'body' => 'Inter'],                  // clean single-family workhorse
                ['heading' => 'Archivo', 'body' => 'Inter'],                // heading w/ more character, neutral body
                ['heading' => 'Libre Franklin', 'body' => 'Source Sans 3'],
            ],
            'bold' => [
                ['heading' => 'Sora', 'body' => 'Inter'],
                ['heading' => 'Space Grotesk', 'body' => 'Inter'],
                ['heading' => 'Poppins', 'body' => 'Work Sans'],
            ],
            'warm' => [
                ['heading' => 'Fraunces', 'body' => 'Source Sans 3'],       // serif head + humanist body
                ['heading' => 'Bitter', 'body' => 'Karla'],
                ['heading' => 'Nunito Sans', 'body' => 'Nunito Sans'],      // humanist single (warm w/o serif)
            ],
        ],

        // Candidate count surfaced by default.
        'candidate_count' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 3 — service-area-localized silo volume
    |--------------------------------------------------------------------------
    |
    | DataForSEO Google Ads search volume, summed across the covered DMAs, is the
    | relative lead-upside signal that drives the Phase 4 prune. `language` is the
    | Keyword Planner language. `fold_threshold` is the advisory granularity floor:
    | a non-pillar spoke whose aggregated monthly volume is below it is recommended
    | to fold into its pillar (own-page otherwise). Advisory only — Phase 4 + the
    | owner confirm; a low-volume core offering can still be kept.
    |
    */

    'silo_volume' => [
        'language' => env('LAUNCHPAD_SILO_VOLUME_LANGUAGE', 'en'),
        'fold_threshold' => (int) env('LAUNCHPAD_SILO_VOLUME_FOLD_THRESHOLD', 50),
    ],

];
