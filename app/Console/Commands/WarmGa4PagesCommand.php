<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Jobs\WarmGa4Pages;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Scheduled WEEKLY warm for the per-page GA4 sessions cache — one bounded {@see WarmGa4Pages} job per
 * engine-eligible site. The Live boards read the per-page GA4 cache only on render (never a live GA4
 * call); this pass keeps that cache populated off-request. Weekly (not hourly like the other live-metrics
 * warm) so the GA4 Data API quota stays bounded — one report per published page/job per week.
 */
class WarmGa4PagesCommand extends Command
{
    protected $signature = 'launchpad:warm-ga4-pages';

    protected $description = 'Warm the per-page GA4 sessions cache (weekly) for every engine-eligible site.';

    /** Sites the board is used for — past onboarding, not suspended (mirrors WarmLiveMetricsCommand). */
    private const ELIGIBLE = [SiteStatus::Active, SiteStatus::Building, SiteStatus::Live];

    public function handle(): int
    {
        $count = 0;
        Site::query()
            ->whereIn('status', array_map(fn (SiteStatus $s) => $s->value, self::ELIGIBLE))
            ->each(function (Site $site) use (&$count): void {
                WarmGa4Pages::dispatch((string) $site->id);
                $count++;
            });

        $this->info("Queued per-page GA4 warm for {$count} site(s).");

        return self::SUCCESS;
    }
}
