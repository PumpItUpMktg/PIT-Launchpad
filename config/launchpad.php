<?php

use App\Local\Grounding\AirQualityProvider;
use App\Local\Grounding\CensusAcsProvider;
use App\Local\Grounding\ClimateNormalsProvider;
use App\Local\Grounding\GoogleElevationProvider;
use App\Local\Grounding\PollenProvider;
use App\Local\Grounding\WaterProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Platform super-users (god mode across every panel)
    |--------------------------------------------------------------------------
    |
    | Emails that get EVERY capability and access to EVERY panel — the operator
    | console AND the white-labeled client portal (with an all-clients switcher).
    | The platform owner's account, independent of the stored role, so it survives
    | any permission change. Comma-separated in LAUNCHPAD_SUPER_USERS.
    */
    'super_users' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('LAUNCHPAD_SUPER_USERS', 'pumpitupmktg@gmail.com')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Client-dashboard metric sync queue
    |--------------------------------------------------------------------------
    |
    | The SyncSiteMetrics jobs (GSC / index / DataForSEO) default to a queue PER
    | provider (metrics:gsc, metrics:index, metrics:dataforseo) so a slow provider
    | can't starve a fast one at scale. That needs a worker consuming those queues.
    |
    | For a single-worker deployment, set LAUNCHPAD_METRICS_QUEUE=default and every
    | metric sync rides the normal `default` queue your standing worker already
    | processes — no per-queue worker configuration required. Leave it unset to keep
    | the per-provider isolation.
    */
    'metrics' => [
        'queue' => env('LAUNCHPAD_METRICS_QUEUE'),

        // Per-run wall-clock budget for the index sync's LIVE URL-Inspection calls (one Google call per
        // URL). A large site can't inspect every URL inside a job timeout, so each run inspects live until
        // this budget is spent, then uses cached verdicts — repeated daily runs + the inspector cache fill
        // coverage over days. Kept well under the queue retry_after / job timeout.
        'index_budget_seconds' => (float) env('LAUNCHPAD_INDEX_BUDGET_SECONDS', 240),

        // The Refresh-button rollup window (days). The one-time ~16-month history is loaded by
        // launchpad:backfill-gsc; the on-demand button only needs to refresh recent data, so it rolls up
        // this trailing window — keeping each chained step small and well inside its job timeout.
        'refresh_window_days' => (int) env('LAUNCHPAD_METRICS_REFRESH_DAYS', 90),
    ],

    'geo' => [
        // AI-search visibility (GEO) audit. Each active prompt is one web-search answer + a Haiku judge,
        // so a run measures prompts until this wall-clock budget is spent; the weekly sweep + a per-prompt
        // freshness window fill coverage over time (mirrors the vitals/index audits).
        'budget_seconds' => (float) env('LAUNCHPAD_GEO_BUDGET_SECONDS', 240),
        'freshness_days' => (int) env('LAUNCHPAD_GEO_FRESHNESS_DAYS', 6),

        // Auto-seed bounding — geo prompts multiply fast (services × towns × intents × engines × cadence),
        // so cap the town fan-out (biggest published towns first) and the total prompts seeded per tenant.
        'seed' => [
            'max_towns' => (int) env('LAUNCHPAD_GEO_SEED_MAX_TOWNS', 40),
            'max_prompts' => (int) env('LAUNCHPAD_GEO_SEED_MAX_PROMPTS', 60),
        ],

        // Assisted weakness top-ups — one Claude call per absent gap, so cap the gaps addressed, variants
        // per gap, and total prompts added per run.
        'topup' => [
            'max_gaps' => (int) env('LAUNCHPAD_GEO_TOPUP_MAX_GAPS', 8),
            'max_variants_per_gap' => (int) env('LAUNCHPAD_GEO_TOPUP_MAX_VARIANTS', 2),
            'max_prompts' => (int) env('LAUNCHPAD_GEO_TOPUP_MAX_PROMPTS', 20),
        ],

        // Check activity log — one row per (prompt × engine) step so the operator can see what the engine
        // is doing; append-only, pruned past this retention window so the table doesn't grow unbounded.
        'events' => [
            'retention_days' => (int) env('LAUNCHPAD_GEO_EVENTS_RETENTION_DAYS', 7),
        ],

        // Gap → content bridge bounding — an absent gap (a prompt no engine cites) becomes ONE directed
        // content candidate that flows through the normal §6 review → publish path and gets re-measured on
        // the next GEO check. Cap how many gaps are bridged per run (priority-market first); idempotent by
        // external_id, so re-running only fills what's newly gone absent.
        'bridge' => [
            'max_gaps' => (int) env('LAUNCHPAD_GEO_BRIDGE_MAX_GAPS', 8),
        ],
    ],

    'vitals' => [
        // Core Web Vitals (PageSpeed Insights) audit. Each URL is one PSI call (~seconds), so a run
        // measures until this wall-clock budget is spent, then leaves the rest for the next run — the
        // weekly sweep + the freshness window fill coverage over time without burning quota.
        'budget_seconds' => (float) env('LAUNCHPAD_VITALS_BUDGET_SECONDS', 240),
        // A URL measured within this many days is skipped (its stored reading is still fresh).
        'freshness_days' => (int) env('LAUNCHPAD_VITALS_FRESHNESS_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local town references on blog posts
    |--------------------------------------------------------------------------
    |
    | A blog post stays relevant to ONE locale: its town tags/links are held to
    | the dominant (county, state) cluster and capped. `ambiguous_town_names`
    | lists extra common-English-word municipality names that must be
    | state-qualified in the copy to count (on top of the built-in list) —
    | prevents "a good deal" tagging Deal, NJ.
    */
    'local_town_cap' => (int) env('LAUNCHPAD_LOCAL_TOWN_CAP', 4),
    'ambiguous_town_names' => [],

    /*
    |--------------------------------------------------------------------------
    | New Setup group (gathering relay) — CUT OVER, default ON
    |--------------------------------------------------------------------------
    |
    | Gates the NEW Setup nav group (the nine-step /admin/setup2/* flow). The
    | cutover is done: this defaults ON, so the final IA (Setup steps 1–9 →
    | Operate → Advanced) is the live menu and the superseded legacy items leave
    | the sidebar. Set LAUNCHPAD_NEW_SETUP=false to fall back to the old menu
    | (every legacy route is still registered). The test suite pins it off in
    | phpunit.xml so flag-off assertions stay the baseline.
    */
    'new_setup_enabled' => (bool) env('LAUNCHPAD_NEW_SETUP', true),

    /*
    |--------------------------------------------------------------------------
    | New Operate group (operate relay) — CUT OVER, default ON
    |--------------------------------------------------------------------------
    |
    | Sibling flag to new_setup_enabled: gates the Operate nav group (Portfolio,
    | Dashboard, the unified Blog pipeline, and the Core/Service/Location pages
    | boards). Defaults ON — Grow and the Local Blog / Live Pages legacy trios
    | leave the sidebar. Set LAUNCHPAD_NEW_OPERATE=false to fall back.
    */
    'new_operate_enabled' => (bool) env('LAUNCHPAD_NEW_OPERATE', true),

    /*
    |--------------------------------------------------------------------------
    | Published board — live-metrics render budget
    |--------------------------------------------------------------------------
    |
    | Seconds a single Published-page render may spend fetching live tracking
    | (GSC / GA4 / Bing) before the remaining cards fall back to a "Refreshing…"
    | state and a background warm pass (WarmLiveMetrics) fills the caches. Keeps a
    | cold-cache, content-heavy page well under the origin/proxy request timeout.
    */
    'published_metrics_budget_seconds' => (float) env('LAUNCHPAD_PUBLISHED_METRICS_BUDGET_SECONDS', 6.0),

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
    | Internal linking (blog posts → the page mesh)
    |--------------------------------------------------------------------------
    |
    | A drafted post weaves internal links into its body: a geographic link to a
    | LIVE location page for each town it names (send juice to the Trooper page),
    | plus the topical link to its silo pillar. Both drill to the most specific
    | live page and are skipped when none exists — never a dead link. Toggle off
    | to fall back to name-only local mentions.
    |
    */

    'internal_linking' => [
        'local_posts' => (bool) env('LAUNCHPAD_LINK_LOCAL_POSTS', true),

        // Inbound-link boost: when a blog post goes live, weave an inbound link to it FROM the strongest
        // already-indexed pages in its own silo (ranked by real GSC impressions), so it inherits their
        // crawl path and indexes faster. Only wraps a phrase the source page ALREADY uses — never
        // fabricates a sentence. `mode`: 'revivals' fires solely for regenerated legacy posts (the initial
        // wave), 'all' generalizes to every published post, 'off' disables it. Tight by default.
        'inbound_boost' => [
            'mode' => env('LAUNCHPAD_INBOUND_BOOST_MODE', 'revivals'),
            'max_sources' => (int) env('LAUNCHPAD_INBOUND_BOOST_MAX_SOURCES', 2),
            'min_source_impressions' => (int) env('LAUNCHPAD_INBOUND_BOOST_MIN_IMPRESSIONS', 1),
        ],

        // Index boost (operator-run, launchpad:boost-indexing): help NEWLY-published PAGES that Google
        // hasn't indexed yet get discovered, by adding a controlled "Related" link to each from a few
        // ALREADY-INDEXED pages (index_verdict=PASS in page_index_states), same silo preferred. The source
        // pages are re-pushed (idempotent by ULID), so Google follows the new crawl path. Bounded so it
        // never bloats a page or reads as a link scheme.
        'index_boost' => [
            'window_days' => (int) env('LAUNCHPAD_INDEX_BOOST_WINDOW_DAYS', 30),  // "newly published" cutoff
            'max_targets' => (int) env('LAUNCHPAD_INDEX_BOOST_MAX_TARGETS', 25),  // new pages boosted per run
            'max_sources_per_target' => (int) env('LAUNCHPAD_INDEX_BOOST_MAX_SOURCES', 3),
            'max_links_per_source' => (int) env('LAUNCHPAD_INDEX_BOOST_MAX_LINKS_PER_SOURCE', 3),  // anti-bloat
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geo Grid (operator-only, internal test build)
    |--------------------------------------------------------------------------
    |
    | Local-pack rank grid via DataForSEO Google-Maps SERP, calibrated against Local Falcon. A single-keyword
    | scan is grid_size² DataForSEO requests per location (49 at 7×7), so cost compounds fast — the scan
    | command is dry-run + hard-ceiling gated (§7). zoom and depth_cap are CALIBRATION CONSTANTS held across
    | every scan (both materially change map-pack composition); verify per-request pricing before the first
    | full cycle. spacing_miles is the default; a per-location `grid_spacing_miles` override wins.
    |
    */

    'geo_grid' => [
        'grid_size' => (int) env('LAUNCHPAD_GEO_GRID_SIZE', 7),          // odd; 7×7 = the Local Falcon parity size
        'spacing_miles' => (float) env('LAUNCHPAD_GEO_GRID_SPACING_MILES', 1.5),
        'zoom' => (int) env('LAUNCHPAD_GEO_GRID_ZOOM', 13),             // calibration constant (matches Local Falcon) — hold across scans
        'depth_cap' => (int) env('LAUNCHPAD_GEO_GRID_DEPTH', 20),       // rank depth; beyond it = not found
        'request_ceiling' => (int) env('LAUNCHPAD_GEO_GRID_REQUEST_CEILING', 5000),  // hard per-run abort (§7)
        'cost_per_request' => (float) env('LAUNCHPAD_GEO_GRID_COST_PER_REQUEST', 0.002),  // ESTIMATE — verify pricing
        'device' => env('LAUNCHPAD_GEO_GRID_DEVICE', 'desktop'),        // fixed device (calibration constant)
        'poll_interval_seconds' => (int) env('LAUNCHPAD_GEO_GRID_POLL_INTERVAL', 5),   // standard-mode task poll
        'poll_max_attempts' => (int) env('LAUNCHPAD_GEO_GRID_POLL_ATTEMPTS', 24),      // ~2 min ceiling per scan
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
    /*
    |--------------------------------------------------------------------------
    | Blog target queue (longtail relay)
    |--------------------------------------------------------------------------
    | The directed:reactive publishing mix — freshness stays news-led while the
    | queue closes gaps steadily. Per-tenant override: Site.directed_mix.
    */
    'blog_queue' => [
        'mix' => env('LAUNCHPAD_BLOG_MIX', '1:2'),
    ],

    // Keyword-first structure generation (accumulate → cluster → derive). Corpus breadth guardrails:
    // cap expansion so the corpus is hundreds of quality terms, not tens of thousands.
    'keyword_first' => [
        'per_seed_cap' => (int) env('LAUNCHPAD_KF_PER_SEED_CAP', 40),   // top-N ideas kept per seed
        'total_cap' => (int) env('LAUNCHPAD_KF_TOTAL_CAP', 600),        // corpus ceiling after merge
        'cluster_cosine' => (float) env('LAUNCHPAD_KF_CLUSTER_COSINE', 0.70), // similarity floor to join a cluster
        'serp_overlap' => (float) env('LAUNCHPAD_KF_SERP_OVERLAP', 0.4),      // head-candidate SERP overlap = same intent
        'service_match_floor' => (float) env('LAUNCHPAD_KF_SERVICE_MATCH', 0.5), // service→cluster match floor (below = flagged)
        'demand_report_volume' => (int) env('LAUNCHPAD_KF_DEMAND_VOLUME', 500), // a head above this with no service = a finding
        // Structure generation runs on the keyword-first pipeline (accumulate→cluster→derive) instead of
        // the catalog-first expander when enabled. Corpus re-accumulates only when older than the window.
        'enabled' => (bool) env('LAUNCHPAD_KEYWORD_FIRST', false),
        'corpus_stale_days' => (int) env('LAUNCHPAD_KF_STALE_DAYS', 30),
    ],

    'grounding' => [
        'stale_days' => 90,

        'sources' => [
            'climate' => ClimateNormalsProvider::class,   // seasonal normals (NOT a live weather API)
            'elevation' => GoogleElevationProvider::class, // per served town; terrain context
            'air_quality' => AirQualityProvider::class,    // stub seam
            'pollen' => PollenProvider::class,             // stub seam
            'census' => CensusAcsProvider::class,          // population / households / housing age
            'water' => WaterProvider::class,               // stub seam (no Google source for hardness)
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

    /*
    |--------------------------------------------------------------------------
    | Severe-weather alert banner (companion plugin)
    |--------------------------------------------------------------------------
    | A LIVE, dismissible "heavy rain expected — is your {noun} ready?" banner
    | the companion plugin renders when Open-Meteo's forecast shows heavy rain
    | ahead. The control plane pushes the config (coords + on/off) on the site
    | profile; the plugin fetches the forecast itself (transient-cached) so the
    | alert stays fresh without republishing. Only rain-relevant trades enable
    | it — the site's captured trade is keyword-matched against `trades`.
    */
    'weather_alert' => [
        'trades' => ['waterproof', 'basement', 'sump', 'foundation', 'drainage', 'plumb', 'flood', 'crawl space'],
        'noun' => 'sump pump',
    ],

    /*
    |--------------------------------------------------------------------------
    | City-keyword tracking (§5 Phase 2)
    |--------------------------------------------------------------------------
    | The patterns a priority-city location page is tracked for. `{head}` is the
    | page's primary/pillar service term (trade-derived, never hardcoded);
    | `{city}` is the market name. Kept deliberately small — DataForSEO local
    | tracking costs per keyword × city — and operator-tunable. The first pattern
    | is the page's headline target keyword (shown on the live card).
    */
    'city_keyword_patterns' => ['{head} {city}', '{head} service {city}'],

    /*
    |--------------------------------------------------------------------------
    | GSC time-series retention (rank-source split relay)
    |--------------------------------------------------------------------------
    | Search Console serves a rolling ~16-month window; anything older is gone
    | for good. We snapshot daily and NEVER overwrite, in a dual grain:
    |
    |  - gsc_url_daily        — one row per (site, date, url); GSC's NATIVE
    |    impression-weighted blended position (pulled with [date,page], so no
    |    client-side re-weighting can bias it). Retained indefinitely — the
    |    per-URL cohort series refresh signals depend on.
    |  - gsc_url_query_daily  — one row per (site, date, url, query, country,
    |    device); full grain kept for `query_grain_retention_days`, then rolled
    |    up into the monthly table and pruned.
    |  - gsc_url_query_monthly — the rollup target (impression-weighted monthly
    |    position); retained indefinitely for the long-term distinct-query and
    |    banded top-3/10/20 trends.
    |
    | Each sync re-pulls a short trailing window (`trailing_repull_days`) and
    | upserts on a grain hash, so GSC's ~3-day revisions + 2–3 day lag are
    | absorbed without ever double-counting. `row_limit` is the GSC page size
    | (max 25000); larger pulls paginate with startRow. `backfill_months` is the
    | one-time recovery depth.
    */
    'gsc' => [
        'trailing_repull_days' => (int) env('LAUNCHPAD_GSC_REPULL_DAYS', 4),
        'query_grain_retention_days' => (int) env('LAUNCHPAD_GSC_QUERY_RETENTION_DAYS', 180),
        'backfill_months' => (int) env('LAUNCHPAD_GSC_BACKFILL_MONTHS', 16),
        'row_limit' => (int) env('LAUNCHPAD_GSC_ROW_LIMIT', 25000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy content revival (migrated-site recovery)
    |--------------------------------------------------------------------------
    | The legacy-redirect planner's "unresolved" bucket is dominated by
    | high-traffic informational URLs the new site has no equivalent for. Rather
    | than 301 them into a service pillar (capturing the equity but losing the
    | specific long-tail ranking), the reviver seeds a REVIEWABLE blog candidate
    | for each — carrying its winning GSC query as the brief — which the operator
    | generates through the normal gated flow; on publish the old URL 301s to the
    | new post (so equity moves to the specific successor). `min_impressions` is
    | the floor for "worth reviving"; `limit` caps a single run.
    */
    'legacy_revival' => [
        'min_impressions' => (int) env('LAUNCHPAD_REVIVE_MIN_IMPRESSIONS', 5000),
        // A slug_overlap family (the planner would 301 it to a pillar) is diverted to revival — keeping
        // its specific ranking instead of consolidating — only when its total impressions clear this.
        'divert_floor' => (int) env('LAUNCHPAD_REVIVE_DIVERT_FLOOR', 20000),
        'limit' => (int) env('LAUNCHPAD_REVIVE_LIMIT', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk re-push throttle
    |--------------------------------------------------------------------------
    | A "Repush published" refreshes the engine-owned meta-blob (canonical / og /
    | schema) across a site's live content. To avoid hammering the client's
    | WordPress with a burst, PublishContent jobs are dispatched in waves — up to
    | `chunk` become available every `interval_seconds`. Idempotent; no fal spend.
    */
    'repush' => [
        'chunk' => (int) env('REPUSH_CHUNK', 10),
        'interval_seconds' => (int) env('REPUSH_INTERVAL_SECONDS', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reactive news lane — topical + geography gate
    |--------------------------------------------------------------------------
    | Before any LLM relevance cost, the reactive (news) funnel drops items that
    | are not buyer-intent for a water/basement/foundation brand — the Aug-4 batch
    | leaked five municipal-utility-finance stories (sewer grants, rate hikes).
    | An item must hit at least one `allow` topic; the finance/governance terms in
    | `deny_context` drop an item that ONLY matches on an incidental utility word.
    | Geography is enforced separately from the site's own footprint states.
    */
    'reactive' => [
        // Master switch for the topical + geography gate. The allow-list below is tuned for a water /
        // basement / foundation brand (Sump Pump Gurus); it is applied INSTALL-WIDE, so a future tenant in
        // a different trade needs its own list (or this off) — move to a per-tenant setting when that lands.
        // Pinned OFF in phpunit.xml so the generic funnel tests keep their pre-gate behavior.
        'enabled' => (bool) env('LAUNCHPAD_REACTIVE_GATE', true),
        'allow' => [
            'flood', 'flooding', 'flooded', 'floodwater', 'flash flood', 'groundwater', 'ground water',
            'storm', 'stormwater', 'hurricane', 'nor\'easter', 'heavy rain', 'rainfall', 'snowmelt',
            'basement water', 'wet basement', 'flooded basement', 'basement flooding', 'damp basement',
            'foundation crack', 'foundation leak', 'foundation repair', 'foundation water', 'settling',
            'sump pump', 'sump', 'french drain', 'drainage', 'yard drainage', 'downspout', 'gutter',
            'water table', 'seepage', 'moisture', 'mold', 'mildew', 'crawl space', 'crawlspace',
            'waterproof', 'waterproofing', 'water damage', 'water intrusion', 'radon', 'egress window',
        ],
        'deny_context' => [
            'rate hike', 'rate increase', 'fee increase', 'utility bill', 'water bill', 'sewer bill',
            'trash', 'garbage', 'recycling', 'grant', 'grants', 'bond', 'budget', 'tax', 'ratepayer',
            'millage', 'assessment fee', 'council approves', 'authority approves', 'ordinance',
            'watershed', 'watershed plan', 'reservoir', // municipal-water governance, not a homeowner topic (§8.6)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Footprint — the states the tenant markets in (§8.2 territory rule)
    |--------------------------------------------------------------------------
    |
    | The single source for "in-footprint": legacy-URL normalization (an
    | in-footprint legacy town → its location page or /areas-we-serve; an
    | out-of-footprint one → 410 to flush the index). Two-letter USPS codes,
    | upper-cased. SPG markets PA/NJ/MD today with NY/CT/DE planned, so all six
    | count as in-footprint (a planned-state legacy URL is parked, not flushed).
    | Install-wide for now (single tenant); per-tenant override lands with the
    | multi-tenant territory work.
    */
    'footprint' => [
        'states' => array_values(array_filter(array_map(
            fn (string $s): string => strtoupper(trim($s)),
            explode(',', (string) env('LAUNCHPAD_FOOTPRINT_STATES', 'PA,NJ,MD,NY,CT,DE'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-tenant output audit (launchpad:audit)
    |--------------------------------------------------------------------------
    | The agency's own business address. A tenant whose corporate address matches
    | this (or another tenant's) is publishing the wrong NAP — NAP-001 flags it.
    | Keep it here (not hardcoded) so no per-tenant special-casing is needed.
    */
    'audit' => [
        'agency_address' => env('LAUNCHPAD_AGENCY_ADDRESS', '377 Valley Road, Clifton, NJ 07013'),
    ],

];
