<?php

namespace App\Jobs;

use App\Console\Commands\SyncGa4Command;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Models\Content;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Warms the per-page GA4 sessions cache for one site's published content AND its published Job Capture
 * pages, OFF the web request, on a WEEKLY beat. This is the per-page half of "GA4 on cron": the daily
 * site-level spine is {@see SyncGa4Command}, and NOTHING pulls per-page GA4 on a
 * render path any more — the boards read {@see PageTrafficProvider::sessionsCached()} only.
 *
 * A weekly cadence keeps the GA4 Data API quota bounded (one report per published page/job per week);
 * {@see PageTrafficProvider::refresh()} FORCE-overwrites the cache (never a remember-hit) so a still-live
 * long-TTL entry is genuinely re-pulled, and the page provider's TTL spans more than a week so the render
 * cache-only read stays fresh between passes.
 *
 * Best-effort and idempotent, bounded like {@see WarmLiveMetrics}: {@see ShouldBeUnique} per site, an
 * explicit {@see $timeout} with {@see $tries} = 1 (never a retry-storm on a vendor hiccup), and the loop
 * time-boxed to {@see SOFT_BUDGET_SECONDS} (under the timeout) so a large site stops gracefully mid-pass
 * — the remaining pages warm next week, or on the next dispatch, as cheap re-pulls.
 */
class WarmGa4Pages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Hard ceiling for one warm pass — well under the queue's retry_after; a pass never runs unbounded. */
    public int $timeout = 120;

    /** Best-effort: a timed-out or erroring warm must not retry-storm. */
    public int $tries = 1;

    /** Uniqueness window (seconds). */
    public int $uniqueFor = 120;

    /** Stop warming after this many seconds — under {@see $timeout} so the pass ends cleanly. */
    private const SOFT_BUDGET_SECONDS = 100;

    public function __construct(public string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(PageTrafficProvider $traffic): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        // No GA4 property connected → nothing to pull (the cards keep their honest "Connect GA4" prompt).
        if ($site === null || ! $traffic->connected($site)) {
            return;
        }

        $deadline = microtime(true) + self::SOFT_BUDGET_SECONDS;

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('status', ContentStatus::Published->value)
            ->get();

        foreach ($pages as $page) {
            if (microtime(true) >= $deadline) {
                return;
            }

            try {
                $traffic->refresh($site, '/'.ltrim((string) $page->slug, '/'));
            } catch (Throwable) {
                // Best-effort — one page's GA4 hiccup must not fail the whole pass.
            }
        }

        // Job Capture pages track on /jobs/{slug}, a path the content warm does not cover.
        $jobs = Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('status', JobStatus::Published->value)
            ->get();

        foreach ($jobs as $job) {
            if (microtime(true) >= $deadline) {
                return;
            }

            try {
                $traffic->refresh($site, $job->publicPath());
            } catch (Throwable) {
                // Best-effort — one job's GA4 hiccup must not fail the whole pass.
            }
        }
    }
}
