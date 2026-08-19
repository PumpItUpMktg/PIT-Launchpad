<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Jobs\WarmLiveMetrics;
use App\Models\Site;
use App\Operate\QueueHealth;
use Illuminate\Console\Command;

/**
 * Scheduled warm for the Published-board live-metrics cache — one bounded {@see WarmLiveMetrics} job per
 * engine-eligible site. The board's reactive warm only fires AFTER a render has already had to defer cards
 * to "Refreshing…", so an operator opening a cold board still sees placeholders; running the warm on a
 * schedule (under the vendor caches' TTL) keeps the cache populated so the board is already warm on load.
 * WarmLiveMetrics is ShouldBeUnique per site, so a scheduled pass and a reactive one never double-run.
 */
class WarmLiveMetricsCommand extends Command
{
    protected $signature = 'launchpad:warm-live-metrics';

    protected $description = 'Warm the Published-board live-metrics cache for every engine-eligible site.';

    /** Sites the board is used for — past onboarding, not suspended (mirrors the §5 driver). */
    private const ELIGIBLE = [SiteStatus::Active, SiteStatus::Building, SiteStatus::Live];

    public function handle(QueueHealth $health): int
    {
        // A prior warm the deploy/timeout interrupted is benign, self-healing noise — clear it so it never
        // lingers in the operator's failed-jobs surface (this warm pass supersedes it anyway).
        $pruned = $health->pruneBenignFailures();

        $count = 0;
        Site::query()
            ->whereIn('status', array_map(fn (SiteStatus $s) => $s->value, self::ELIGIBLE))
            ->each(function (Site $site) use (&$count): void {
                WarmLiveMetrics::dispatch((string) $site->id);
                $count++;
            });

        $this->info("Queued live-metrics warm for {$count} site(s)".($pruned > 0 ? "; cleared {$pruned} stale warm failure(s)." : '.'));

        return self::SUCCESS;
    }
}
