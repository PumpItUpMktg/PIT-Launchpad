<?php

namespace App\Jobs;

use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * On-demand ranking pull for ONE site — the queued worker behind the operator's "Refresh rankings
 * now" button. Runs {@see SitePipelineRefresher::trackNow()} (force positions-only: every scored
 * keyword, both lanes, bypassing the cadence/budget gates). Positions-only — it does NOT discover or
 * score new keywords, so the DataForSEO spend is bounded and was shown to the operator as an estimate
 * before confirming. Off the web request so the multi-keyword external pull never times out an FPM
 * worker.
 *
 * In LIVE mode {@see trackNow()} both fetches and records synchronously, so the pull is complete here.
 * In STANDARD mode it only POSTS the async tasks — the snapshot lands on a later read — so this chains
 * a delayed {@see FinalizeSitePositions} that re-reads the ingested cache and writes the snapshots,
 * making the button self-complete within minutes instead of waiting for the daily pipeline.
 */
class RefreshSitePositions implements ShouldQueue
{
    use Queueable;

    /** A real multi-keyword SERP + grid pull across the site — give it room. */
    public int $timeout = 600;

    /** No auto-retry: a mid-flight failure shouldn't re-post the whole external pull. */
    public int $tries = 1;

    public function __construct(public readonly string $siteId) {}

    /** Lead time before the first finalize pass — lets the IngestSerpTasks sweep collect the first results. */
    private const FINALIZE_LEAD_MINUTES = 2;

    public function handle(SitePipelineRefresher $refresher): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $refresher->trackNow($site);

        // Standard mode only posts here; chain the finalize passes that read the ingested cache into
        // snapshots. Live mode already recorded synchronously above — no finalize needed.
        if (config('services.dataforseo.mode', 'standard') === 'standard') {
            FinalizeSitePositions::dispatch($this->siteId)
                ->delay(Carbon::now()->addMinutes(self::FINALIZE_LEAD_MINUTES));
        }
    }
}
