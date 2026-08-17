<?php

namespace App\Jobs;

use App\Enums\PipelineTrigger;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduled §5 refresh for ONE site — dispatched per eligible site by the {@see RefreshKeywordPipelines}
 * driver's fan-out. Runs the cadence-gated discovery + two-lane position-tracking sweep
 * ({@see SitePipelineRefresher::refresh()}) as its own BOUNDED job.
 *
 * Why per-site: the sweep posts DataForSEO SERP tasks under a per-minute rate cap (default 12/min), so a
 * keyword-heavy site spends most of its time throttled. The old driver ran EVERY site inline in a single
 * job with no timeout — inheriting the worker's 60s default — so it blew the timeout and wrote no
 * snapshots. One bounded job per site (matching the on-demand {@see RefreshSitePositions} budget) keeps
 * each tenant's sweep isolated and inside a real timeout.
 *
 * Best-effort: `$tries = 1` (no retry storm on an expensive external pull — the daily cadence retries next
 * cycle), and because SERP tasks are posted-then-collected, even a partial run persists progress that the
 * IngestSerpTasks sweep + a later read finalize into snapshots.
 */
class RefreshSitePipeline implements ShouldQueue
{
    use Queueable;

    /** A real multi-keyword, rate-limited SERP + grid sweep — matches the on-demand RefreshSitePositions budget (< the 630s queue retry_after). */
    public int $timeout = 600;

    /** Best-effort: no auto-retry on an expensive external sweep; the daily cadence naturally retries. */
    public int $tries = 1;

    public function __construct(public readonly string $siteId) {}

    public function handle(SitePipelineRefresher $refresher): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        try {
            $refresher->refresh($site, PipelineTrigger::Scheduled);
        } catch (Throwable $e) {
            // One site's provider hiccup must not fail the job noisily; the artifact-based cadence retries.
            Log::warning('§5 pipeline refresh failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
