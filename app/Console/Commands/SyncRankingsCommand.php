<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\DataForSeoMetricProvider;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Refresh the rank-tracking slice of the metric spine (§ Client Dashboard v1, PR 4). Fans out
 * {@see SyncSiteMetrics} for the `dataforseo` provider — one job per site on the `metrics:dataforseo`
 * queue — which rolls up the §5 `position_snapshots` series into per-keyword rank + site standings.
 *
 *   sandhog:sync-rankings {site?} {--days=7}
 *
 * `--days` bounds the per-keyword rows written (a trailing window; default 7 for the daily beat — pass a
 * large value once for the initial backfill). Site standings are always as-of now (carry-forward from all
 * history), so a short window still yields correct current standings. Idempotent, so re-runs are safe.
 */
class SyncRankingsCommand extends Command
{
    protected $signature = 'sandhog:sync-rankings
        {site? : the Site id (default: every visible site)}
        {--days=7 : trailing window of per-keyword rank rows to (re)write}';

    protected $description = 'Roll up §5 position snapshots into the metric spine (per-keyword rank + site standings), per site.';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No site found.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $start = Carbon::now()->subDays($days)->toDateString();
        $end = Carbon::now()->toDateString();

        foreach ($sites as $site) {
            SyncSiteMetrics::dispatch($site->id, DataForSeoMetricProvider::PROVIDER, $start, $end);
            $this->line(sprintf('   %-28s → rankings sync queued (%s … %s)', $site->domain_url ?? $site->id, $start, $end));
        }

        $this->info(sprintf('Queued %d rankings sync job(s) on the %s queue.', $sites->count(), SyncSiteMetrics::queueFor(DataForSeoMetricProvider::PROVIDER)));

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
}
