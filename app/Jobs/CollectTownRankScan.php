<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\TownRankScan;
use App\TownRank\TownRankScanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Collects ONE pending town-rank scan (§ Town Rank): the digestible unit the every-minute
 * {@see IngestTownRankScans} dispatcher hands out, one job per scan, so 56 scans collect side by side across
 * the worker lanes and a failure in one scan never stalls the others. Reads the scan's uncollected tasks
 * directly by id ({@see TownRankScanner::collectDirect()}) — no shared, capped "ready" list — up to the
 * per-run read budget inside a wall-clock deadline short of the timeout. A scan still pending past the
 * expiry window closes as `partial` over what it has. Unique per scan, so a minute's dispatch never stacks
 * behind a run already collecting that scan.
 */
class CollectTownRankScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 300;

    /** Seconds kept back from the timeout so the read in flight and the finalize never race the kill. */
    private const DEADLINE_MARGIN_SECONDS = 40;

    public function __construct(public readonly string $scanId)
    {
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->scanId;
    }

    public function handle(TownRankScanner $scanner): void
    {
        $scan = TownRankScan::withoutGlobalScope(SiteScope::class)->find($this->scanId);
        if ($scan === null || $scan->status !== 'pending') {
            return;
        }

        $started = microtime(true);
        $deadline = $started + max(30, $this->timeout - self::DEADLINE_MARGIN_SECONDS);
        $budget = max(1, (int) config('launchpad.town_rank.ingest_batch', 400));

        $spent = $scanner->collectDirect($scan, $budget, $deadline);
        $scan->refresh();

        $expiryCutoff = Carbon::now()->subHours(max(1, (int) config('launchpad.town_rank.pending_expiry_hours', 24)));
        $expired = false;
        if ($scan->status === 'pending' && $scan->scanned_at !== null && $scan->scanned_at->lt($expiryCutoff)) {
            $scanner->finalize($scan, 'partial');
            $expired = true;
        }

        Log::info('Town-rank scan collector: run finished.', [
            'scan_id' => $scan->id,
            'task_gets' => $spent,
            'status' => $scan->fresh()?->status,
            'expired_partial' => $expired,
            'seconds' => round(microtime(true) - $started, 1),
        ]);
    }
}
