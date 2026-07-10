<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Standard pages
    |--------------------------------------------------------------------------
    |
    | Data-gating thresholds for the optional standard pages (Step 4). A toggle is
    | only offered when the site clears the bar — e.g. Gallery needs photos.
    */
    'standard_pages' => [
        'reviews_min' => 1,
        'gallery_min' => 3,
    ],

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
        'fold_threshold' => (int) env('LAUNCHPAD_SILO_VOLUME_FOLD_THRESHOLD', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | auto-arrange — structural auto-arrangement of the silo-volume output
    |--------------------------------------------------------------------------
    |
    | auto-arrange takes the raw silo-volume tree and produces the recommended,
    | cannibalization-safe, properly-nested structure automatically: it auto-
    | resolves the mechanical decisions and flags the judgment calls for operator
    | confirm (the same advisory pattern as the dead-silo flag). Every relatedness
    | decision rides on the §6a EmbeddingProvider — never hand-rolled string match.
    | These cosine thresholds are sane starting points to tune from live output;
    | each is per-site overridable (later). Pass keys:
    |
    |  - dedup_cosine: Pass B — two spokes nearer than this are one keyword/one home.
    |  - dedup_ambiguity_margin: relative volume gap below which a dedup winner is
    |    "close" → flag for operator confirm (still applied as the default).
    |  - nest_floor: Pass A — a folded spoke nests under its most-related own-page
    |    core only above this; below it falls back to the pillar (safe) + flags.
    |
    */

    'auto_arrange' => [
        'dedup_cosine' => (float) env('LAUNCHPAD_ARRANGE_DEDUP_COSINE', 0.85),
        'dedup_ambiguity_margin' => (float) env('LAUNCHPAD_ARRANGE_DEDUP_MARGIN', 0.15),
        'nest_floor' => (float) env('LAUNCHPAD_ARRANGE_NEST_FLOOR', 0.70),

        // Pass C — fraction of a silo's spokes whose nearest neighbor sits in one other
        // silo, above which the silo is flagged to demote to a sub-hub under it (advisory).
        'sub_hub_overlap' => (float) env('LAUNCHPAD_ARRANGE_SUBHUB_OVERLAP', 0.60),

        // Pass D — two pages whose primary keywords are nearer than this collide (cannibalize).
        'collision_cosine' => (float) env('LAUNCHPAD_ARRANGE_COLLISION_COSINE', 0.90),

        // Pass A re-runs — an existing auto fold target only re-flips when a new candidate
        // beats the current score by at least this band (anti-thrash; a hair never moves it).
        'reflip_margin' => (float) env('LAUNCHPAD_ARRANGE_REFLIP_MARGIN', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locations — county-based coverage
    |--------------------------------------------------------------------------
    |
    | Covered towns are grouped by ACS population into Large / Medium / Small for
    | the operator's at-a-glance read. Thresholds are inclusive at the Medium floor:
    | Large > large, Medium >= medium, Small below.
    |
    */

    'locations' => [
        'population_buckets' => [
            'large' => (int) env('LAUNCHPAD_POP_LARGE', 25000),
            'medium' => (int) env('LAUNCHPAD_POP_MEDIUM', 15000),
        ],

        // The 4-tier page-selection grouping (major/large/medium/small + ungrouped). Per-tenant
        // overridable on the Site (coverage_thresholds JSON); these are the platform defaults.
        // Inclusive floors: Major >= major, Large >= large, Medium >= medium, else Small;
        // population null => ungrouped (no tier).
        'size_tiers' => [
            'major' => (int) env('LAUNCHPAD_TIER_MAJOR', 50000),
            'large' => (int) env('LAUNCHPAD_TIER_LARGE', 30000),
            'medium' => (int) env('LAUNCHPAD_TIER_MEDIUM', 15000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Location-page drip (per-business local relevance)
    |--------------------------------------------------------------------------
    |
    | Town pages don't all build at once. The biggest towns (by Census population)
    | build immediately; the rest sit in reserve and "drip" live as each earns
    | enough local relevance for that specific business — competitor density,
    | review footprint, and local demand resolved per (site, town) through the
    | LocalSignalProvider seam, so no two sites use the same data.
    |
    | - auto_select_tiers: which size tiers are built immediately on first setup.
    | - drip_threshold: the 0–1 relevance score a reserve town must reach to graduate.
    | - weights: how the normalized signals blend into the relevance score.
    |
    */

    'drip' => [
        'auto_select_tiers' => ['major', 'large'],

        'drip_threshold' => (float) env('LAUNCHPAD_DRIP_THRESHOLD', 0.55),

        'weights' => [
            'population' => 0.45,
            'demand' => 0.30,
            'reviews' => 0.25,
            // Competitor saturation is subtracted from the blended score.
            'competition_penalty' => 0.20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Location-page grounding (trade-keyed local facts)
    |--------------------------------------------------------------------------
    | Provider-agnostic enrichment for location pages: the tenant's trade picks
    | which sources fire per location; results cache on the Location record
    | (grounding_cache) and refetch only when stale. Drafter input ONLY — never
    | rendered as live page widgets. A missing key / failed fetch skips the
    | source and logs; grounding is never a generation blocker.
    */
    'grounding' => [
        'stale_days' => 90,

        'sources' => [
            'climate' => App\Local\Grounding\ClimateNormalsProvider::class,   // seasonal normals (NOT a live weather API)
            'elevation' => App\Local\Grounding\GoogleElevationProvider::class, // per served town; terrain context
            'air_quality' => App\Local\Grounding\AirQualityProvider::class,    // stub seam
            'pollen' => App\Local\Grounding\PollenProvider::class,             // stub seam
            'census' => App\Local\Grounding\CensusAcsProvider::class,          // population / households / housing age
            'water' => App\Local\Grounding\WaterProvider::class,               // stub seam (no Google source for hardness)
        ],

        'trade_map' => [
            'waterproofing' => ['climate', 'elevation', 'census'],
            'plumbing' => ['climate', 'census'],
            'mold_testing' => ['air_quality', 'climate', 'census'],
            'hvac' => ['climate', 'air_quality', 'pollen', 'census'],
            'water_treatment' => ['water', 'census'],
            '_default' => ['census'],
        ],
    ],

];
