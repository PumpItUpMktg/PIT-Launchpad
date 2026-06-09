<?php

namespace App\Integrations\Google;

use App\Models\Site;
use DateTimeInterface;

/**
 * Capability role: first-party Google Search Console search-analytics for a site
 * (§5 calibration input). Net-new seam — §5 has no GSC consumer today
 * (SiteAuthority calibrates off DataForSEO position history); this supplies the
 * normalized rows, wiring them into §5 calibration is a later §5 change.
 */
interface SearchConsoleProvider
{
    /**
     * @param  list<string>  $dimensions  query|page|date|country|device
     * @return list<SearchAnalyticsRow>
     */
    public function searchAnalytics(
        Site $site,
        DateTimeInterface $start,
        DateTimeInterface $end,
        array $dimensions = ['query'],
        int $rowLimit = 1000,
    ): array;
}
