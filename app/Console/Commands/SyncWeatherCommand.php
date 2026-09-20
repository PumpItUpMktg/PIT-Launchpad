<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\WeatherMetricProvider;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Refresh the observed-rainfall spine — daily inches per NOAA station into `metric_snapshots`, the series
 * a result trend is read against when a tenant's demand moves with the weather. Fans out
 * {@see SyncSiteMetrics} for the `noaa` provider, one job per site.
 *
 *   launchpad:sync-weather {--site=} {--days=}
 *
 * The trailing window costs nothing extra: NOAA answers a station in one request whatever the range, so a
 * wide window is the same call as a narrow one and absorbs the service's ~3-day publication lag without
 * needing to reason about it. Free and keyless — this is the one sync on the platform that spends neither
 * credits nor a vendor quota.
 */
class SyncWeatherCommand extends Command
{
    protected $signature = 'launchpad:sync-weather
        {--site= : Site id (default: every site)}
        {--days= : Days of history to refresh (default: the configured metrics refresh window)}';

    protected $description = 'Refresh observed daily rainfall (NOAA) into the metric spine, per site.';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No site found.');

            return self::FAILURE;
        }

        $days = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : max(1, (int) config('launchpad.metrics.refresh_window_days', 90));

        $today = Carbon::now()->toDateString();
        $start = Carbon::now()->subDays($days)->toDateString();

        foreach ($sites as $site) {
            SyncSiteMetrics::dispatch($site->id, WeatherMetricProvider::PROVIDER, $start, $today);
            $this->line(sprintf('   %-28s → rainfall sync queued', $site->brand_name));
        }

        $this->info(sprintf(
            'Queued %d rainfall sync job(s) for %s → %s on the %s queue.',
            $sites->count(), $start, $today, SyncSiteMetrics::queueFor(WeatherMetricProvider::PROVIDER),
        ));

        return self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function resolveSites(): Collection
    {
        $id = $this->option('site');
        if (is_string($id) && $id !== '') {
            $site = Site::query()->find($id);

            return $site === null ? collect() : collect([$site]);
        }

        return Site::query()->get();
    }
}
