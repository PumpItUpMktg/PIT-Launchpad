<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\SiteStatus;
use App\Jobs\RefreshSitePipeline;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled §5 driver — the missing caller. It FANS OUT: one bounded {@see RefreshSitePipeline} job per
 * engine-eligible site, rather than sweeping every site inline. The sweep posts rate-capped DataForSEO SERP
 * tasks (default 12/min), so a keyword-heavy site is inherently slow; running them all in one job — which,
 * lacking a $timeout, inherited the worker's 60s default — blew the timeout and wrote no snapshots. Each
 * per-site job carries its own 600s budget and isolates one tenant's failure from the rest; the per-site
 * cadence lives in SitePipelineRefresher, so quiet sites cost nothing.
 *
 * This driver itself only dispatches (fast, never times out); the actual work + per-site error handling is
 * the child job's.
 */
class RefreshKeywordPipelines implements ShouldQueue
{
    use Queueable;

    /** Sites the engine runs for — past onboarding, not suspended. */
    private const ELIGIBLE = [SiteStatus::Active, SiteStatus::Building, SiteStatus::Live];

    public function handle(): void
    {
        Site::query()
            ->whereIn('status', array_map(fn (SiteStatus $s) => $s->value, self::ELIGIBLE))
            ->each(fn (Site $site) => RefreshSitePipeline::dispatch((string) $site->id));
    }
}
