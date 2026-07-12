<?php

namespace App\Integrations\Analytics;

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
}
