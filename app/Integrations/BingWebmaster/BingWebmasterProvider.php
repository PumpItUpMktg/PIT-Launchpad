<?php

namespace App\Integrations\BingWebmaster;

use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\PageSearchStats;
use App\Models\Site;

/**
 * Per-page Bing Webmaster Tools stats for the Live boards — the Bing analog of {@see
 * \App\Integrations\SearchConsole\SearchConsoleProvider}, mirroring its contract exactly (and reusing its
 * DTOs) so the consuming surfaces treat Bing and Google identically. Vendor-deferred: the normalized
 * contract ships with a {@see NullBingWebmaster} default; {@see BingWebmaster} (a single agency BWT API
 * key + a per-Site verified `bing_site_url`) binds when the key is configured.
 *
 * `connected()` drives the source state + the honest per-cell prompt — a disconnected source renders a
 * "Connect Bing Webmaster" prompt, never a fabricated zero, exactly like the GSC seam.
 */
interface BingWebmasterProvider
{
    public function connected(Site $site): bool;

    /** Impressions + clicks for one page path over the window, or null while Bing has no data yet. */
    public function pageStats(Site $site, string $path, int $days = 28): ?PageSearchStats;

    /**
     * The top Bing search queries one page was found for, most impressions first. Empty while the
     * source is disconnected or has no data yet.
     *
     * @return list<PageQuery>
     */
    public function pageQueries(Site $site, string $path, int $days = 28, int $limit = 8): array;
}
