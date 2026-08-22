<?php

namespace App\Console\Commands;

use App\Metrics\Providers\GscMetricProvider;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Verify the GSC rollup (§ Client Dashboard v1, PR 2): does the metric spine's site-level impressions/clicks
 * match the raw `gsc_url_daily` source it rolls up from? This is the SPG integrity check — run it after a
 * backfill to confirm the spine faithfully reproduces the GSC pull (and the printed totals are what the
 * operator eyeballs against the Search Console UI).
 *
 *   sandhog:verify-gsc {site} {--days=28}
 */
class VerifyGscMetricsCommand extends Command
{
    protected $signature = 'sandhog:verify-gsc
        {site : the Site id}
        {--days=28 : trailing window to compare (days)}';

    protected $description = 'Compare the GSC metric-spine site totals against the raw gsc_url_daily source.';

    public function handle(): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $end = Carbon::now()->toDateString();
        $start = Carbon::now()->subDays($days)->toDateString();

        // Source of truth: the raw daily GSC store the provider rolls up from.
        $source = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM(impressions),0) AS impr, COALESCE(SUM(clicks),0) AS clicks, COUNT(DISTINCT url) AS pages')
            ->first();

        // The spine: site-level daily rows written by the GSC provider.
        $spine = DB::table('metric_snapshots')
            ->where('site_id', $site->id)
            ->where('provider', GscMetricProvider::PROVIDER)
            ->where('dimension_type', 'site')
            ->where('period_grain', 'day')
            ->whereBetween('period_date', [$start, $end])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN metric_key = 'impressions' THEN value_numeric ELSE 0 END),0) AS impr,
                COALESCE(SUM(CASE WHEN metric_key = 'clicks' THEN value_numeric ELSE 0 END),0) AS clicks")
            ->first();

        $srcImpr = (int) ($source->impr ?? 0);
        $srcClicks = (int) ($source->clicks ?? 0);
        $spineImpr = (int) round((float) ($spine->impr ?? 0));
        $spineClicks = (int) round((float) ($spine->clicks ?? 0));

        $this->line(sprintf('Site: <info>%s</info>  window: %s … %s (%d days)', $site->brand_name ?? $site->id, $start, $end, $days));
        $this->newLine();
        $this->table(
            ['Metric', 'gsc_url_daily (source)', 'metric_snapshots (spine)', 'match'],
            [
                ['impressions', number_format($srcImpr), number_format($spineImpr), $this->mark($srcImpr === $spineImpr)],
                ['clicks', number_format($srcClicks), number_format($spineClicks), $this->mark($srcClicks === $spineClicks)],
            ],
        );
        $this->line(sprintf('   source distinct pages in window: %d', (int) ($source->pages ?? 0)));

        if ($srcImpr === 0 && $spineImpr === 0) {
            $this->warn('No GSC data in the window — run launchpad:backfill-gsc then sandhog:backfill-gsc first.');

            return self::SUCCESS;
        }

        if ($srcImpr === $spineImpr && $srcClicks === $spineClicks) {
            $this->info('✓ Spine matches the GSC source.');

            return self::SUCCESS;
        }

        $this->error('✗ Spine does NOT match the GSC source — re-run the backfill for this window.');

        return self::FAILURE;
    }

    private function mark(bool $ok): string
    {
        return $ok ? '<info>✓</info>' : '<error>✗</error>';
    }
}
