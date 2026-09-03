<?php

namespace App\Integrations\Analytics;

use App\Jobs\WarmLiveMetrics;
use App\Models\Site;

/**
 * Per-page traffic (GA4) for the Live boards — mock-first like the §7c ConversionProvider seam
 * (which stays lead-level; this one is page-level). The real GA4 Data API adapter binds later with
 * per-tenant property credentials; until then `connected()` is false and the cells show the
 * connect prompt, never zeros.
 */
interface PageTrafficProvider
{
    public function connected(Site $site): bool;

    /** Sessions for one page path over the window, or null while the source has no data yet. */
    public function sessions(Site $site, string $path, int $days = 28): ?int;

    /**
     * The CACHE-ONLY twin of {@see sessions()} — the warmed session count if present, else null WITHOUT
     * ever hitting GA4. For a render path that must do zero outbound HTTP: {@see \App\Jobs\WarmGa4Pages}
     * populates the cache off-request (weekly), and a cache-miss here renders an honest "Refreshing…"
     * instead of fetching inline. Null covers both "not warmed yet" and "warmed with no data".
     */
    public function sessionsCached(Site $site, string $path, int $days = 28): ?int;

    /**
     * FORCE-REFRESH the cached count: always fetch from GA4 and overwrite the cache entry (never a
     * remember-hit), so the weekly {@see \App\Jobs\WarmGa4Pages} pass actually re-pulls even while a
     * prior long-TTL entry is still live. This is the ONLY writer of the render cache now that
     * {@see sessions()} is off the render/warm paths; the render reads {@see sessionsCached()}.
     */
    public function refresh(Site $site, string $path, int $days = 28): ?int;
}
