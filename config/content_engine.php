<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Engine
    |--------------------------------------------------------------------------
    |
    | Engine-level tunables and provider selection. The provider flags also back
    | the per-service credential groups in config/services.php (same env vars);
    | the recency/backfill windows drive the §6a candidate funnel.
    |
    */

    // Steady-state freshness cutoff: items older than this are not drafted.
    'recency_days' => (int) env('CONTENT_ENGINE_RECENCY_DAYS', 90),

    // First-run backfill lookback for silo-discovery.
    'backfill_days' => (int) env('CONTENT_ENGINE_BACKFILL_DAYS', 365),

    // Selected providers (mock adapters bind by default until real keys are set).
    'image_provider' => env('CONTENT_ENGINE_IMAGE_PROVIDER', 'fal'),
    'news_provider' => env('NEWS_PROVIDER', 'newsapi'),
    'embeddings_provider' => env('EMBEDDINGS_PROVIDER', 'openai'),

    // §5 keyword-pipeline driver cadence (days). The scheduled driver re-runs a
    // site's unit only when its newest durable artifact is older than this — so
    // position tracking refreshes on a regular beat and discovery runs slower.
    // The operator "refresh now" action bypasses both.
    //
    // Tracking is WEEKLY: DataForSEO bills per SERP task and organic positions
    // don't move enough day-to-day to justify a daily spend (the daily driver
    // still runs, but a site's tracking is skipped until a week has passed). This
    // value is also the freshness interval the rankings stamps read
    // (App\Support\Cadence 'serp'), so the two never drift.
    'pipeline' => [
        'tracking_cadence_days' => (int) env('KEYWORD_TRACKING_CADENCE_DAYS', 7),
        'discovery_cadence_days' => (int) env('KEYWORD_DISCOVERY_CADENCE_DAYS', 7),
    ],

];
