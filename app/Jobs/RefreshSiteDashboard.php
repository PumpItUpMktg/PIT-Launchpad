<?php

namespace App\Jobs;

use App\Metrics\Providers\DataForSeoMetricProvider;
use App\Metrics\Providers\GscMetricProvider;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

/**
 * One-click "Refresh data" for a site's client dashboard (§ Client Dashboard v1) — the queued work behind
 * the dashboard's Refresh button and a friendlier alternative to the CLI backfill runbook.
 *
 * It does NOT do the work itself — it dispatches a CHAIN of separate, individually-timeout-bounded jobs:
 * GSC rollup → DataForSEO rankings → index inspection → milestone derivation. Each step is its own job with
 * its own timeout, so a large site can never blow a single job's budget (an earlier all-in-one version timed
 * out mid-run and left the later steps — keywords, index, milestones — unwritten). Steps run in order (each
 * after the previous completes), and SyncSiteMetrics swallows provider errors onto its sync-run row so one
 * provider hiccup doesn't halt the chain.
 *
 * Rollup windows are bounded to a recent refresh window: the one-time ~16-month history is loaded by
 * launchpad:backfill-gsc, and the button just refreshes recent data, keeping every step small.
 */
class RefreshSiteDashboard implements ShouldQueue
{
    use Queueable;

    /** Trivial — this job only builds and dispatches the chain, so it can't time out. */
    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public readonly string $siteId) {}

    public function handle(): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $today = Carbon::now()->toDateString();
        $windowDays = max(1, (int) config('launchpad.metrics.refresh_window_days', 90));
        $windowStart = Carbon::now()->subDays($windowDays)->toDateString();

        // Each step its own job (own timeout). Order: fast DB rollups, the budget-bounded index inspection,
        // then milestones last so they read everything the syncs just wrote.
        Bus::chain([
            new SyncSiteMetrics($this->siteId, GscMetricProvider::PROVIDER, $windowStart, $today),
            new SyncSiteMetrics($this->siteId, DataForSeoMetricProvider::PROVIDER, $windowStart, $today),
            new SyncSiteMetrics($this->siteId, IndexMetricProvider::PROVIDER, $today, $today),
            new DeriveSiteMilestones($this->siteId),
        ])->dispatch();
    }
}
