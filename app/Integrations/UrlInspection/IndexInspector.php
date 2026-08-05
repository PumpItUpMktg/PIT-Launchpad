<?php

namespace App\Integrations\UrlInspection;

use App\Models\Site;

/**
 * The per-URL index-coverage seam (Google Search Console URL Inspection API), vendor-deferred like every
 * external integration: the normalized contract ships with a Null default; the real adapter binds when
 * the shared Google grant is live and the Site has a GSC property picked.
 *
 * URL Inspection is quota-limited (2,000/day + 600/min per property) and one call PER url — so unlike the
 * cheap searchAnalytics totals, cards must NOT inspect live on every render. The split:
 *  - {@see inspect()} performs a live (cache-first, quota-guarded) inspection — used by the batched audit.
 *  - {@see cached()} returns ONLY a previously-cached result (no API call) — used by card rendering.
 */
interface IndexInspector
{
    public function connected(Site $site): bool;

    /**
     * Live inspection for one URL — cache-first, and skipped (returns null) when the per-day quota cap is
     * reached. Null also on a transient API error or a disconnected source.
     */
    public function inspect(Site $site, string $url): ?IndexStatus;

    /** The cached inspection for a URL, or null if none has been fetched yet — never makes an API call. */
    public function cached(Site $site, string $url): ?IndexStatus;
}
