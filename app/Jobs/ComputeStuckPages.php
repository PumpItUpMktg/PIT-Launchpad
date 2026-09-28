<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\StuckPages;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Computes one site's stuck-page report ({@see StuckPages}: Google's verdict, inbound links, impressions,
 * lever, recommendation per page past the stuck window) on the queue and parks it in the cache for the
 * Indexing board's "Why?" panel. The report builds the whole site's internal-link graph — every published
 * body parsed for links — which on a few hundred pages is far too slow for a web request (it timed out
 * silently behind a Livewire click). The panel is now a pure read: this job runs after every index sync
 * (daily, and on "Re-check indexing now") and on demand when a panel is opened with nothing cached.
 * Unique per site so a burst of clicks queues one computation.
 */
class ComputeStuckPages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Cached report lifetime — the daily sync refreshes it well inside this. */
    public const CACHE_SECONDS = 86400;

    /** How long a "computing" marker holds off a second dispatch. */
    public const PENDING_SECONDS = 300;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public static function cacheKey(string $siteId): string
    {
        return 'indexing:stuck-pages:'.$siteId;
    }

    public static function pendingKey(string $siteId): string
    {
        return 'indexing:stuck-pages:pending:'.$siteId;
    }

    /** Queue a computation unless one is already marked pending; returns whether one was queued. */
    public static function request(string $siteId): bool
    {
        if (! Cache::add(self::pendingKey($siteId), true, self::PENDING_SECONDS)) {
            return false;
        }
        self::dispatch($siteId);

        return true;
    }

    public function handle(StuckPages $stuck): void
    {
        try {
            $site = Site::withoutGlobalScope(SiteScope::class)->find($this->siteId);
            if ($site === null) {
                return;
            }

            $rows = collect($stuck->for($site)['rows'])->keyBy('content_id')->all();
            Cache::put(self::cacheKey($this->siteId), ['rows' => $rows, 'computed_at' => now()->toIso8601String()], self::CACHE_SECONDS);
        } finally {
            Cache::forget(self::pendingKey($this->siteId));
        }
    }
}
