<?php

namespace App\Jobs;

use App\Integrations\DataForSeo\IngestSerpTasks;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\PositionSnapshot;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * The BACK HALF of the on-demand "Refresh rankings now" pull — the piece that makes the button
 * self-complete instead of waiting for the daily driver.
 *
 * {@see RefreshSitePositions} posts the standard-mode SERP/grid tasks (front half) and chains this
 * job on a short delay. DataForSEO resolves tasks asynchronously (~5–15 min) and the every-5-min
 * {@see IngestSerpTasks} sweep collects each result into cache; this job
 * then re-reads that cache and writes the {@see PositionSnapshot} the cards display
 * ({@see SitePipelineRefresher::finalize()}).
 *
 * Because a single pass can run before the vendor has finished, it re-arms itself up to
 * {@see MAX_ATTEMPTS} times across the vendor's stated window. Each pass is safe and idempotent:
 * a not-yet-ingested keyword re-reads through the deduped dispatcher (never re-posts, never
 * double-spends) and `finalize()`'s recent-capture guard skips a keyword already recorded this cycle,
 * so the retries never stack duplicate snapshots. A keyword the site simply doesn't rank for writes
 * nothing — correctly — and the attempt cap bounds the whole thing.
 */
class FinalizeSitePositions implements ShouldQueue
{
    use Queueable;

    /** A multi-keyword cache re-read across the site — give it room (still bounded, no external posts). */
    public int $timeout = 600;

    /** No auto-retry: the self-rearm below is the retry mechanism, so a failed pass shouldn't also stack. */
    public int $tries = 1;

    /** Bounded passes so a never-ingesting task (or a non-ranking site) can't loop forever. */
    private const MAX_ATTEMPTS = 5;

    /** Spacing between passes — MAX_ATTEMPTS × this comfortably spans DataForSEO's 5–15 min resolve window. */
    private const RETRY_DELAY_MINUTES = 3;

    public function __construct(public readonly string $siteId, public readonly int $attempt = 1) {}

    public function handle(SitePipelineRefresher $refresher): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $refresher->finalize($site);

        if ($this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->siteId, $this->attempt + 1)
                ->delay(Carbon::now()->addMinutes(self::RETRY_DELAY_MINUTES));
        }
    }
}
