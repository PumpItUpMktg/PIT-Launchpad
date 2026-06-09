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

];
