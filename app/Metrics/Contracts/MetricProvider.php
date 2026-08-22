<?php

namespace App\Metrics\Contracts;

use App\Metrics\SyncResult;
use App\Models\Site;
use Carbon\CarbonPeriod;

/**
 * A source of client-dashboard metrics (§ Client Dashboard v1). Each provider (gsc, dataforseo, internal)
 * pulls its own data for a site over a date range and writes idempotent rows into `metric_snapshots`,
 * returning a SyncResult. Providers are registered in the MetricProviderRegistry under their key() and
 * dispatched onto their own queue by SyncSiteMetrics.
 *
 * Providers must be safe to re-run: every write is an upsert on MetricSnapshot::GRAIN_KEYS.
 */
interface MetricProvider
{
    /** Stable identifier — the provider column value and the queue suffix (metrics:{key}). */
    public function key(): string;

    /** Pull the site's metrics over the range and upsert them into the spine. */
    public function sync(Site $site, CarbonPeriod $range): SyncResult;
}
