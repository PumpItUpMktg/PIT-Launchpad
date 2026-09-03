<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Guided\LiveMetrics;
use App\JobCapture\Metrics\JobMetrics;
use App\Models\Content;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Warms the live-metrics caches (GSC / Bing / index / position) for one site's published content AND its
 * published Job Capture pages, OFF the web request. GA4 sessions are NOT warmed here — they are on a
 * separate WEEKLY beat ({@see \App\Jobs\WarmGa4Pages}) to keep the GA4 Data API quota bounded, so this
 * hourly pass passes `liveTraffic: false` and never pulls GA4. The content Published board and the
 * Published-Jobs board both dispatch this (sharing one throttle lock) so their render paths read warmed
 * caches only and never call a vendor inline; the worker calls {@see LiveMetrics::for()} for each live
 * page and {@see JobMetrics::for()} for each live job — the same cache-backed fetch, just on a queue with
 * no FPM clock — so the next board load is warm and fast. Jobs track on /jobs/{slug}, a path the content
 * warm does not cover, so they are warmed explicitly here.
 *
 * Best-effort and idempotent: a per-page vendor error is swallowed (that card simply stays "collecting"),
 * and {@see ShouldBeUnique} + the board's dispatch lock keep at most one warm pass per site in flight.
 *
 * Bounded like its sibling jobs so a large site can't time the worker out: an explicit job {@see $timeout}
 * (a warm pass never runs unbounded) with {@see $tries} = 1 (best-effort — never retry-storm), and the
 * loop is TIME-BOXED to {@see SOFT_BUDGET_SECONDS} — comfortably under the timeout — so it stops
 * gracefully mid-pass instead of being killed by the queue and landing in `failed_jobs` (which would trip
 * the operator "stalled" banner for what is a benign warm). Pages warmed this pass are cache-hits next
 * pass, so successive board-triggered dispatches converge on a fully-warm site.
 */
class WarmLiveMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Hard ceiling for one warm pass — well under the queue's retry_after; a pass never runs unbounded. */
    public int $timeout = 120;

    /** Best-effort: a timed-out or erroring warm must not retry-storm (the board re-dispatches anyway). */
    public int $tries = 1;

    /** Uniqueness window (seconds) — matches the board's dispatch lock. */
    public int $uniqueFor = 120;

    /** Stop warming after this many seconds — under {@see $timeout} so the pass ends cleanly, never timing out. */
    private const SOFT_BUDGET_SECONDS = 100;

    public function __construct(public string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(LiveMetrics $metrics, JobMetrics $jobMetrics): void
    {
        $deadline = microtime(true) + self::SOFT_BUDGET_SECONDS;

        $live = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('status', ContentStatus::Published->value)
            ->with(['site', 'targetKeyword.targetContent'])
            ->get();

        foreach ($live as $page) {
            // Stop before the queue timeout: the remaining pages warm on the next dispatch (already-warmed
            // pages are cheap cache-hits then), so this converges without ever failing the job.
            if (microtime(true) >= $deadline) {
                return;
            }

            try {
                // Side effect only: populates the per-(property × path) vendor caches LiveMetrics reads.
                // GA4 is warmed separately on a WEEKLY beat by WarmGa4Pages (liveTraffic:false skips it
                // here), so this hourly pass keeps GSC/index/position/Bing warm without an hourly GA4 pull.
                $metrics->for($page, liveTraffic: false);
            } catch (Throwable) {
                // Warming is best-effort — one page's vendor hiccup must not fail the whole pass.
            }
        }

        // Job Capture pages track on a DIFFERENT path (/jobs/{slug}) than any Content slug, so the content
        // warm above does NOT cover them — the Published-Jobs board reads its own warmed caches. Warm the
        // site's published jobs within the SAME budget; already-warmed jobs are cache-hits on the next pass,
        // so the combined set converges without ever failing the job.
        $jobs = Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('status', JobStatus::Published->value)
            ->with('site')
            ->get();

        foreach ($jobs as $job) {
            if (microtime(true) >= $deadline) {
                return;
            }

            try {
                // Fetching (not cache-only) populates the GSC cache JobMetrics reads on render; GA4 is
                // left to the weekly WarmGa4Pages pass (liveTraffic:false), so no hourly GA4 pull here.
                $jobMetrics->for($job, liveTraffic: false);
            } catch (Throwable) {
                // Best-effort — one job's vendor hiccup must not fail the whole pass.
            }
        }
    }
}
