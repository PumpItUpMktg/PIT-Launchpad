<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteMetrics;
use App\Jobs\WarmGa4Pages;
use App\Metrics\Providers\Ga4MetricProvider;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Refresh the site-level GA4 spine — daily `sessions` per site into `metric_snapshots` (the slice the
 * client dashboard's traffic funnel and "visits vs search clicks" trend read). Fans out
 * {@see SyncSiteMetrics} for the `ga4` provider, one job per site, over a trailing refresh window so
 * GA4's late-arriving data is absorbed idempotently.
 *
 *   sandhog:sync-ga4 {site?}
 *
 * This is the SITE-LEVEL half of "GA4 on cron" (run daily). The per-page sessions the Live boards show
 * are a different cache, warmed WEEKLY by {@see WarmGa4Pages}; neither runs on a render path.
 * A clean no-op for a tenant with no GA4 property connected (the provider reports not-connected).
 */
class SyncGa4Command extends Command
{
    protected $signature = 'sandhog:sync-ga4 {site? : the Site id (default: every site)}';

    protected $description = 'Refresh the site-level GA4 daily-sessions spine (metric_snapshots), per site.';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No site found.');

            return self::FAILURE;
        }

        $today = Carbon::now()->toDateString();
        $windowStart = Carbon::now()->subDays(max(1, (int) config('launchpad.metrics.refresh_window_days', 90)))->toDateString();

        foreach ($sites as $site) {
            SyncSiteMetrics::dispatch($site->id, Ga4MetricProvider::PROVIDER, $windowStart, $today);
            $this->line(sprintf('   %-28s → GA4 sync queued', $site->domain_url ?? $site->id));
        }

        $this->info(sprintf('Queued %d GA4 sync job(s) on the %s queue.', $sites->count(), SyncSiteMetrics::queueFor(Ga4MetricProvider::PROVIDER)));

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
