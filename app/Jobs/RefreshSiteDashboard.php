<?php

namespace App\Jobs;

use App\Metrics\MetricProviderRegistry;
use App\Metrics\Milestones\MilestoneDeriver;
use App\Metrics\Providers\DataForSeoMetricProvider;
use App\Metrics\Providers\GscMetricProvider;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-click "Refresh data" for a site's client dashboard (§ Client Dashboard v1) — the queued work behind
 * the dashboard's Refresh button and a friendlier alternative to the CLI backfill runbook.
 *
 * It runs the whole spine chain IN ORDER within a single job — GSC rollup → durable index state → keyword
 * rankings → milestones — by invoking the already-tested {@see SyncSiteMetrics} logic inline (so each step
 * still records a metric_sync_run) then deriving milestones last (they read what the syncs just wrote).
 *
 * It runs on the DEFAULT queue on purpose: the platform's standing worker already consumes `default`, so no
 * per-provider queue configuration is needed for the button to work. Every step is idempotent, and each is
 * isolated so one provider hiccup can't abort the rest. It rolls up data already collected (gsc_url_daily,
 * position_snapshots) and refreshes index state via URL Inspection; the one-time ~16-month GSC history pull
 * from Google stays the separate `launchpad:backfill-gsc`.
 */
class RefreshSiteDashboard implements ShouldQueue
{
    use Queueable;

    /** Bounded to stay under the database queue's retry_after so a slow run can't be double-picked. */
    public int $timeout = 600;

    public int $tries = 1;

    /** How far back each provider (re)builds — generous windows; idempotent, so cost is bounded by data volume. */
    private const GSC_MONTHS = 16;

    private const RANK_DAYS = 500;

    public function __construct(public readonly string $siteId) {}

    public function handle(MetricProviderRegistry $registry, MilestoneDeriver $deriver): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $today = Carbon::now()->toDateString();
        $steps = [
            [GscMetricProvider::PROVIDER, Carbon::now()->subMonths(self::GSC_MONTHS)->toDateString(), $today],
            [IndexMetricProvider::PROVIDER, $today, $today],
            [DataForSeoMetricProvider::PROVIDER, Carbon::now()->subDays(self::RANK_DAYS)->toDateString(), $today],
        ];

        foreach ($steps as [$provider, $start, $end]) {
            try {
                // Inline reuse of the queued sync's logic: opens/records a metric_sync_run and runs the
                // provider. SyncSiteMetrics::handle swallows provider errors onto the run, so this won't throw.
                (new SyncSiteMetrics($this->siteId, $provider, $start, $end))->handle($registry);
            } catch (Throwable $e) {
                Log::warning('dashboard refresh step failed', ['site_id' => $site->id, 'provider' => $provider, 'error' => $e->getMessage()]);
            }
        }

        try {
            $deriver->derive($site);
        } catch (Throwable $e) {
            Log::warning('dashboard milestone derivation failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
