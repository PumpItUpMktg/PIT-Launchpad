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
 */
class WarmLiveMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Uniqueness window (seconds) — matches the board's dispatch lock. */
    public int $uniqueFor = 120;

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

        foreach ($live as $page) {
            try {
                // Side effect only: populates the per-(property × path) vendor caches LiveMetrics reads.
                $metrics->for($page);
            } catch (Throwable) {
                // Warming is best-effort — one page's vendor hiccup must not fail the whole pass.
            }
        }
    }
}
