<?php

namespace App\Jobs;

use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * On-demand ranking pull for ONE site — the queued worker behind the operator's "Refresh rankings
 * now" button. Runs {@see SitePipelineRefresher::trackNow()} (force positions-only: every scored
 * keyword, both lanes, bypassing the cadence/budget gates). Positions-only — it does NOT discover or
 * score new keywords, so the DataForSEO spend is bounded and was shown to the operator as an estimate
 * before confirming. Off the web request so the multi-keyword external pull never times out an FPM
 * worker; in standard mode the SERP tasks are posted here and finalize on the IngestSerpTasks sweep.
 */
class RefreshSitePositions implements ShouldQueue
{
    use Queueable;

    /** A real multi-keyword SERP + grid pull across the site — give it room. */
    public int $timeout = 600;

    /** No auto-retry: a mid-flight failure shouldn't re-post the whole external pull. */
    public int $tries = 1;

    public function __construct(public readonly string $siteId) {}

    public function handle(SitePipelineRefresher $refresher): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $refresher->trackNow($site);
    }
}
