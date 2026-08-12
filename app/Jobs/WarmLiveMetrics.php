<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Guided\LiveMetrics;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Warms the live-metrics caches (GSC / GA4 / Bing / index) for one site's published content, OFF the web
 * request. The Published board dispatches this when a render hit its metrics budget and had to defer
 * cards to a "Refreshing…" state; the worker calls {@see LiveMetrics::for()} for each live page — the
 * same cache-backed fetch, just on a queue with no FPM clock — so the next board load is warm and fast.
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

    public function handle(LiveMetrics $metrics): void
    {
        $live = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('status', ContentStatus::Published->value)
            ->with(['site', 'targetKeyword.targetContent'])
            ->get();

        $deadline = microtime(true) + self::SOFT_BUDGET_SECONDS;

        foreach ($live as $page) {
            // Stop before the queue timeout: the remaining pages warm on the next dispatch (already-warmed
            // pages are cheap cache-hits then), so this converges without ever failing the job.
            if (microtime(true) >= $deadline) {
                break;
            }

            try {
                // Side effect only: populates the per-(property × path) vendor caches LiveMetrics reads.
                $metrics->for($page);
            } catch (Throwable) {
                // Warming is best-effort — one page's vendor hiccup must not fail the whole pass.
            }
        }
    }
}
