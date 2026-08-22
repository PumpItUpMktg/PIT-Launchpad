<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\GscMetricProvider;
use App\Models\MetricSyncRun;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Backfill the GSC slice of the metric spine (§ Client Dashboard v1) — one month-chunk at a time so a long
 * history stays inside per-job limits and is resumable. It fans out {@see SyncSiteMetrics} on the `metrics:gsc`
 * queue; each job runs {@see GscMetricProvider}, which rolls up the existing
 * `gsc_url_daily` store into snapshots (so the source GSC history must already be present — the daily sync
 * and `launchpad:backfill-gsc` fill it).
 *
 *   sandhog:backfill-gsc {site?} {--months=16} {--resume}
 *
 * - no site → every visible Site (operator backfill of the whole portfolio)
 * - --months=N → how far back to walk, in calendar months (default 16, the GSC retention window)
 * - --resume → skip month-chunks a successful run already covers (safe to re-run after an interruption)
 *
 * NOTE the `sandhog:` namespace (not `launchpad:`): the existing `launchpad:backfill-gsc`
 * ({@see BackfillGscCommand}) already pulls the GSC history into `gsc_url_daily`. This backfills the
 * client-dashboard SPINE — the GSC provider rolls UP from `gsc_url_daily` into `metric_snapshots` rather
 * than re-hit the Search Console API.
 */
class BackfillGscMetricsCommand extends Command
{
    public const PROVIDER = 'gsc';

    protected $signature = 'sandhog:backfill-gsc
        {site? : the Site id (default: every visible site)}
        {--months=16 : how many calendar months back to backfill}
        {--resume : skip month-chunks already covered by a successful sync run}';

    protected $description = 'Backfill GSC metrics into the client-dashboard spine, one resumable month-chunk per site.';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $resume = (bool) $this->option('resume');

        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No site found.');

            return self::FAILURE;
        }

        $chunks = self::monthChunks(Carbon::now(), $months);
        $dispatched = 0;

        foreach ($sites as $site) {
            foreach ($chunks as [$start, $end]) {
                if ($resume && $this->alreadyCovered($site->id, $start, $end)) {
                    continue;
                }

                SyncSiteMetrics::dispatch($site->id, self::PROVIDER, $start->toDateString(), $end->toDateString());
                $dispatched++;
            }

            $this->line(sprintf('   %-28s → %d month-chunk(s) queued', $site->domain_url ?? $site->id, count($chunks)));
        }

        $this->info(sprintf('Queued %d GSC backfill job(s) across %d site(s) on the %s queue.',
            $dispatched, $sites->count(), 'metrics:'.self::PROVIDER));

        return self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function resolveSites(): Collection
    {
        $id = $this->argument('site');
        if ($id !== null) {
            $site = Site::query()->find($id);

            return $site === null ? collect() : collect([$site]);
        }

        return Site::query()->get();
    }

    /**
     * Walk back `$months` calendar months from `$now`, one chunk per month (clamped to today at the tail).
     * Oldest chunk first, so a resumable backfill fills history front-to-back.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public static function monthChunks(Carbon $now, int $months): array
    {
        $chunks = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $now->copy()->subMonths($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            if ($end->greaterThan($now)) {
                $end = $now->copy()->startOfDay();
            }
            $chunks[] = [$start, $end];
        }

        return $chunks;
    }

    private function alreadyCovered(string $siteId, Carbon $start, Carbon $end): bool
    {
        return MetricSyncRun::withoutGlobalScopes()
            ->where('site_id', $siteId)
            ->where('provider', self::PROVIDER)
            ->where('status', MetricSyncRun::STATUS_SUCCESS)
            ->whereDate('range_start', $start->toDateString())
            ->whereDate('range_end', $end->toDateString())
            ->exists();
    }
}
