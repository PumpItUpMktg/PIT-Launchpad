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
        // GEO (AI-search visibility): the web-search-capable model + how many searches per answer.
        'geo_model' => env('ANTHROPIC_GEO_MODEL', 'claude-sonnet-4-6'),
        'geo_web_search_max_uses' => (int) env('ANTHROPIC_GEO_MAX_USES', 5),
        'drafting_model' => env('ANTHROPIC_DRAFTING_MODEL', 'claude-sonnet-4-6'),
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
        // Bound every Claude call well below the drafting job's $timeout (600s) and the queue's
        // retry_after (630s). The SDK defaults are timeout=600 × maxRetries=2 → up to 1800s per call,
        // which lets a slow API response run past retry_after: the worker re-reserves the still-running
        // job and it dies with MaxAttemptsExceeded. timeout×(1+retries) must stay under 600s.
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 240),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 1),
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

    // Cloudflare API (agency-wide token) — auto-configures the edge for a tenant's WordPress so the
    // control-plane sync isn't blocked/stripped. One scoped token over the account that holds the
    // client zones; the zone is resolved per site by domain. Needs Zone → Zone (Read) + Zone → WAF
    // (Edit). Absent token → the seam falls back to a no-op mock and the action reports "not configured".
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'timeout' => (int) env('CLOUDFLARE_TIMEOUT', 20),
    ],

    // DataForSEO — SERP + keyword data (§5). `mode` selects the standard
    // (task-based, cheaper) vs live (synchronous) request mode.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        'mode' => env('DATAFORSEO_DEFAULT_MODE', 'standard'),
        'timeout' => (int) env('DATAFORSEO_TIMEOUT', 30),
        // Rate limiting: proactively space requests to stay under the account's per-minute cap (a
        // discovery burst otherwise trips status_code 40202), and the reactive backoff per retry when
        // the cap is hit anyway. Tests set backoff to 0 to skip the sleep.
        'rate_limit_per_min' => (int) env('DATAFORSEO_RATE_LIMIT_PER_MIN', 12),
        'rate_limit_backoff_ms' => (int) env('DATAFORSEO_RATE_LIMIT_BACKOFF_MS', 5000),
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
        // Approximate per-task USD cost, used only to ESTIMATE an on-demand pull's spend for the
        // operator's confirmation disclaimer (not billing). Defaults track DataForSEO Standard-queue
        // list prices: SERP Google Organic Advanced ≈ $0.0012, Google Maps ≈ $0.002 per task.
        'serp_task_cost' => (float) env('DATAFORSEO_SERP_TASK_COST', 0.0012),
        'maps_task_cost' => (float) env('DATAFORSEO_MAPS_TASK_COST', 0.002),
        // Cache TTL (hours) guarding against re-fetch inside the refresh cadence.
        'cache_ttl_hours' => (int) env('DATAFORSEO_CACHE_TTL_HOURS', 168),
        // Google Ads locations catalog (metro → location_code resolution): country scope + cache.
        'locations_country' => env('DATAFORSEO_LOCATIONS_COUNTRY', 'US'),
        'locations_cache_days' => (int) env('DATAFORSEO_LOCATIONS_CACHE_DAYS', 30),
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
    'perplexity' => [
        // GEO engine (Perplexity Sonar) — answers with native citations. Disabled (no-op) without a key.
        'key' => env('PERPLEXITY_API_KEY'),
        'base_url' => env('PERPLEXITY_BASE_URL', 'https://api.perplexity.ai'),
        'model' => env('PERPLEXITY_MODEL', 'sonar'),
        'timeout' => (int) env('PERPLEXITY_TIMEOUT', 60),
    ],

    'pagespeed' => [
        // Google PageSpeed Insights (Lighthouse) — free Core Web Vitals for the client "Site speed" card.
        // No per-tenant auth; an optional API key lifts the anonymous quota. Off → the card shows "not
        // measured yet" instead of scores.
        'enabled' => (bool) env('PAGESPEED_ENABLED', true),
        'base_url' => env('PAGESPEED_BASE_URL', 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'),
        'api_key' => env('PAGESPEED_API_KEY'),
        'timeout' => (int) env('PAGESPEED_TIMEOUT', 60),
    ],

    'google' => [
        'project_id' => env('GOOGLE_PROJECT_ID'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
        // Google Geocoding API (address → point) — uses the maps key (Geocoding API enabled).
        'geocoder_url' => env('GOOGLE_GEOCODER_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'auth_uri' => env('GOOGLE_AUTH_URI', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_uri' => env('GOOGLE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'gsc_base_url' => env('GOOGLE_GSC_BASE_URL', 'https://www.googleapis.com/webmasters/v3'),
        // Path (on the site's own domain) of the sitemap submitted to Search Console for indexing.
        // The companion plugin serves the Launchpad sitemap at /sitemap.xml (no SEO plugin needed).
        'sitemap_path' => env('GOOGLE_SITEMAP_PATH', '/sitemap.xml'),
        // After a bulk re-push, submit the (freshly re-lastmod'd) sitemap to Search Console ONCE per run
        // so Google re-crawls the changed set. Debounced per-site so back-to-back re-pushes don't spam
        // GSC — sitemaps.submit is idempotent and Google recrawls from <lastmod> on its own schedule, so
        // more than one submit per window buys nothing and Google discourages repeated resubmits.
        'sitemap_submit_on_repush' => (bool) env('GOOGLE_SITEMAP_SUBMIT_ON_REPUSH', true),
        'sitemap_submit_debounce_hours' => (int) env('GOOGLE_SITEMAP_SUBMIT_DEBOUNCE_HOURS', 12),
        // URL Inspection API — the AUTHORITATIVE per-URL index-coverage signal (coverageState: indexed /
        // crawled-not-indexed / excluded-by-redirect …), distinct from impressions>0. It lives on a
        // different host (searchconsole.googleapis.com/v1) than the searchAnalytics base above. Quota is
        // 2,000/day + 600/min per property, so an audit is BATCHED + CACHED (results change ~daily) and
        // capped per day; cards read the cached result, never fire a live inspection on render.
        'gsc_inspection_base_url' => env('GOOGLE_GSC_INSPECTION_BASE_URL', 'https://searchconsole.googleapis.com/v1'),
        // 14 days. At ~60-120 live inspections/run (daily) and ~474 known URLs, a 3-day TTL forced
        // ~158 re-inspections/day — above throughput — so the budget re-chewed the head and never
        // reached the tail. 14 days drops re-inspection demand to ~34/day (474/14), leaving ≥26/day
        // headroom even at the low end of throughput; combined with uninspected-first ordering
        // (IndexCoverage) the backlog drains instead of widening.
        'url_inspection_cache_ttl' => (int) env('GOOGLE_URL_INSPECTION_CACHE_TTL', 1209600), // 14 days
        'url_inspection_daily_cap' => (int) env('GOOGLE_URL_INSPECTION_DAILY_CAP', 1800),    // under the 2,000/day quota
        // Per-page GSC totals are cached this long on the Live cards (GSC data lags ~2-3 days, so a
        // board render need not re-query every card). Default 6h.
        'gsc_cache_ttl' => (int) env('GOOGLE_GSC_CACHE_TTL', 21600),
        'ga4_data_base_url' => env('GOOGLE_GA4_DATA_BASE_URL', 'https://analyticsdata.googleapis.com/v1beta'),
        // Site-level GA4 spine (Ga4SiteTraffic → metric_snapshots) cache TTL. Default 6h.
        'ga4_cache_ttl' => (int) env('GOOGLE_GA4_CACHE_TTL', 21600),
        // Per-page GA4 session totals (Ga4PageTraffic) are read cache-only on render and refreshed WEEKLY
        // off-request by WarmGa4Pages, so this TTL must span more than a week — otherwise the render's
        // cache-only read goes cold between weekly warms. Default 8 days.
        'ga4_page_cache_ttl' => (int) env('GOOGLE_GA4_PAGE_CACHE_TTL', 691200),
        'ga4_admin_base_url' => env('GOOGLE_GA4_ADMIN_BASE_URL', 'https://analyticsadmin.googleapis.com/v1beta'),
        'timeout' => (int) env('GOOGLE_TIMEOUT', 30),
    ],

    // IndexNow — instant "please crawl" ping to Bing, Yandex, Seznam, Naver (one endpoint fans out
    // to all participants; Google does NOT participate). Free, keyless-per-request: the site's key is
    // generated by the control plane and served by the companion plugin at /{key}.txt. `ping_on_publish`
    // fires a single-URL ping when a page goes live; the operator can also submit the whole site.
    'indexnow' => [
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
        'enabled' => (bool) env('INDEXNOW_ENABLED', true),
        'ping_on_publish' => (bool) env('INDEXNOW_PING_ON_PUBLISH', true),
        'timeout' => (int) env('INDEXNOW_TIMEOUT', 15),
    ],

    // Bing Webmaster Tools — the Bing analog of Search Console (impressions/clicks/position for your
    // pages). ONE agency-level API key (Settings → API access in BWT); each client site is verified in
    // BWT (easiest: "import from Google Search Console"), and its verified site URL is stored per-tenant
    // on sites.bing_site_url. Absent key → the Null adapter (cards show "Submitted to Bing" only).
    'bing' => [
        'api_key' => env('BING_WEBMASTER_API_KEY'),
        'base_url' => env('BING_WEBMASTER_BASE_URL', 'https://ssl.bing.com/webmaster/api.svc/json'),
        'timeout' => (int) env('BING_WEBMASTER_TIMEOUT', 15),
        'cache_ttl' => (int) env('BING_WEBMASTER_CACHE_TTL', 21600),
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
        'tigerweb_counties_layer' => (int) env('CENSUS_TIGERWEB_COUNTIES_LAYER', 82),
        // ACS5 population (county-subdivision grouping). REQUIRES a key — keyless requests
        // redirect to a "Missing Key" page; without it, towns come back ungrouped.
        'acs_year' => env('CENSUS_ACS_YEAR', '2022'),
        'tigerweb_timeout' => (int) env('CENSUS_TIGERWEB_TIMEOUT', 30),
        // Census Geocoder (keyless, US-only) — address → point for the Locations base.
        'geocoder_url' => env('CENSUS_GEOCODER_URL', 'https://geocoding.geo.census.gov/geocoder'),
        'geocoder_benchmark' => env('CENSUS_GEOCODER_BENCHMARK', 'Public_AR_Current'),
        'geocoder_timeout' => (int) env('CENSUS_GEOCODER_TIMEOUT', 15),
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
