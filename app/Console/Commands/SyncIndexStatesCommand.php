<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\IndexMetricProvider;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Refresh durable Google index state (§ Client Dashboard v1, PR 3). Fans out {@see SyncSiteMetrics} for the
 * `index` provider — one job per site on the `metrics:index` queue — which runs the quota-guarded URL
 * Inspection, upserts `page_index_states`, and stamps today's `pages_indexed` / `pages_known` snapshot.
 *
 *   sandhog:sync-index {site?}
 *
 * Safe to run daily: the inspector is cache-first (multi-day TTL), so a run only spends quota on stale or
 * new URLs and naturally spreads a large site's inspection across days.
 */
class SyncIndexStatesCommand extends Command
{
    protected $signature = 'sandhog:sync-index {site? : the Site id (default: every visible site)}';

    protected $description = 'Refresh durable Google index state (page_index_states) + the pages-indexed snapshot, per site.';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No site found.');

            return self::FAILURE;
        }

        $today = Carbon::now()->toDateString();
        foreach ($sites as $site) {
            SyncSiteMetrics::dispatch($site->id, IndexMetricProvider::PROVIDER, $today, $today);
            $this->line(sprintf('   %-28s → index sync queued', $site->domain_url ?? $site->id));
        }

        $this->info(sprintf('Queued %d index sync job(s) on the metrics:%s queue.', $sites->count(), IndexMetricProvider::PROVIDER));

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
