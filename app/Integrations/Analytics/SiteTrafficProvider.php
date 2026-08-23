<?php

namespace App\Integrations\Analytics;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Site-level daily traffic (GA4 sessions) for the metric spine — the site-wide sibling of the per-page
 * {@see PageTrafficProvider}. One report over a date range with a `date` dimension yields the daily
 * sessions series the client dashboard's traffic funnel and "visits vs search clicks" trend read from.
 * Mock-first: until a tenant's GA4 property is connected, `connected()` is false and the ingest no-ops
 * (the dashboard shows the honest "connect GA4" state, never a fabricated zero).
 */
interface SiteTrafficProvider
{
    public function connected(Site $site): bool;

    /**
     * Daily sessions over [start, end], keyed Y-m-d. Null when the source isn't connected or has no data
     * yet (distinct from a real zero-session day).
     *
     * @return array<string, int>|null
     */
    public function dailySessions(Site $site, Carbon $start, Carbon $end): ?array;
}
