<?php

namespace App\Analytics\Gsc;

use App\Models\Site;
use Carbon\CarbonImmutable;

/**
 * The one-time recovery of Search Console history — pulls the full available
 * window (roughly the last 16 months; GSC retains no more) at both grains, then
 * rolls the aged query-grain into the monthly table. This is time-sensitive: every
 * day without it loses another day off the far end of GSC's rolling window,
 * permanently. Runnable per tenant; idempotent (same grain-hash upserts as the
 * daily sync), so a re-run over an already-captured window changes nothing.
 *
 * The reported `earliest_available` is the honest floor: a property verified
 * recently returns less than the requested window, and that shows up as an
 * earliest date later than `requested_start`.
 */
class GscBackfill
{
    public function __construct(
        private readonly GscSnapshotIngestor $ingestor,
        private readonly GscRollup $rollup,
    ) {}

    /**
     * @return array{
     *   requested_start: string, earliest_available: ?string, latest: ?string,
     *   url_daily: int, url_query_daily: int, rolled_months: int, daily_pruned: int
     * }
     */
    public function run(Site $site, ?int $months = null): array
    {
        $months = $months ?? (int) config('launchpad.gsc.backfill_months', 16);
        $end = CarbonImmutable::now();
        $start = $end->subMonths(max(1, $months));

        $pulled = $this->ingestor->pull($site, $start, $end);
        $rolled = $this->rollup->run($site);

        return [
            'requested_start' => $start->toDateString(),
            'earliest_available' => $pulled['earliest_date'],
            'latest' => $pulled['latest_date'],
            'url_daily' => $pulled['url_daily'],
            'url_query_daily' => $pulled['url_query_daily'],
            'rolled_months' => $rolled['months'],
            'daily_pruned' => $rolled['daily_pruned'],
        ];
    }
}
