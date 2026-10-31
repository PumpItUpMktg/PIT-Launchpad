<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankRecollectProgress;
use App\TownRank\TownRankScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-read a site's phantom and masked town-rank points on the worker (§ Town Rank) — the board's "Re-read
 * masked towns" click, and `launchpad:town-rank-recollect --queue`. Reads are free (the provider's stored
 * task results), but a whole-site backlog is tens of thousands of them, so the job works in time-boxed
 * chunks well inside the queue's retry window and re-dispatches itself for the rest; the progress record
 * ({@see TownRankRecollectProgress}) is what the board shows and what refuses a second chain while one is
 * live. Not ShouldBeUnique on purpose: a unique lock is held until the job finishes, which would swallow
 * the continuation it dispatches from inside `handle`.
 */
class RecollectTownRank implements ShouldQueue
{
    use Queueable;

    /** Seconds of reading per chunk — under the job timeout, far under the queue's 930s retry window. */
    public const CHUNK_SECONDS = 420;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $siteId,
        public readonly ?string $keywordId = null,
        public readonly bool $continuation = false,
        public readonly int $chunkSeconds = self::CHUNK_SECONDS,
    ) {}

    /**
     * Start a chain for a site unless one is live. Returns the backlog size it will work through, or null
     * when a chain is already running (nothing dispatched).
     */
    public static function start(string $siteId, ?string $keywordId = null): ?int
    {
        if (TownRankRecollectProgress::isRunning($siteId)) {
            return null;
        }
        $total = self::backlog($siteId, $keywordId);
        TownRankRecollectProgress::start($siteId, $total);
        if ($total === 0) {
            TownRankRecollectProgress::finish($siteId);

            return 0;
        }
        self::dispatch($siteId, $keywordId);

        return $total;
    }

    /** The finished scans with work left, newest first (the current picture is repaired before history). @return Collection<int, TownRankScan> */
    public static function scans(string $siteId, ?string $keywordId = null): Collection
    {
        $q = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at');
        if ($keywordId !== null) {
            $q->where('keyword_id', $keywordId);
        }

        return $q->get();
    }

    /** Phantom + masked points still to re-read across the site's scans — one SQL count (the page reads this). */
    public static function backlog(string $siteId, ?string $keywordId = null): int
    {
        return TownRankScanner::backlogCount($siteId, $keywordId);
    }

    /** @return Collection<int, TownRankPoint> */
    private static function work(TownRankScan $scan): Collection
    {
        return TownRankScanner::phantoms($scan)->concat(TownRankScanner::masked($scan))->unique('id')->values();
    }

    public function handle(TownRankScanner $scanner): void
    {
        $deadline = microtime(true) + max(5, $this->chunkSeconds);
        $read = 0;
        $ranked = 0;
        $notFound = 0;
        $skipped = 0;
        try {
            foreach (self::scans($this->siteId, $this->keywordId) as $scan) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $points = self::work($scan);
                if ($points->isEmpty()) {
                    continue;
                }
                $r = $scanner->recollect($scan, $points, $deadline);
                $read += $r['read'];
                $ranked += $r['ranked'];
                $notFound += $r['not_found'];
                $skipped += $r['skipped'];
            }
        } catch (Throwable $e) {
            Log::warning('Town-rank recollect job stopped.', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            TownRankRecollectProgress::fail($this->siteId, $e->getMessage());

            return;
        }

        $remaining = self::backlog($this->siteId, $this->keywordId);
        TownRankRecollectProgress::advance($this->siteId, $read, $ranked, $notFound, $skipped, $remaining);

        // Unreadable points stay in the backlog by design (left as they were), so "remaining" alone would
        // chain forever on a dead task: continue only when this chunk actually resolved something.
        if ($remaining > 0 && ($read - $skipped) > 0) {
            self::dispatch($this->siteId, $this->keywordId, true, $this->chunkSeconds);

            return;
        }
        TownRankRecollectProgress::finish($this->siteId);
    }
}
