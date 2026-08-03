<?php

namespace App\Integrations\SearchConsole;

use App\Models\Site;

/**
 * Per-page Search Console stats for the Live boards — vendor-deferred like every external seam:
 * the normalized contract ships first with a Null default; the real GSC API adapter binds later
 * (per-tenant property credentials as a §9 Connection) with no change to the consuming surfaces.
 * `connected()` drives the source chip + the honest per-cell "connect" state — a disconnected
 * source renders a prompt, never a fabricated zero.
 */
interface SearchConsoleProvider
{
    public function connected(Site $site): bool;

    /** Stats for one page path over the window, or null while the source has no data yet. */
    public function pageStats(Site $site, string $path, int $days = 28): ?PageSearchStats;

    /**
     * The top search queries one page was found for over the window — the free, complete long tail
     * (every "sump pump {city}" / "near me" variant GSC recorded), most impressions first. Empty
     * while the source is disconnected or has no data yet.
     *
     * @return list<PageQuery>
     */
    public function pageQueries(Site $site, string $path, int $days = 28, int $limit = 8): array;
}
