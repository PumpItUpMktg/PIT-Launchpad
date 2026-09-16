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
 * The town-rank collection sweep (§ Town Rank, PR 3) — the async half of {@see RunTownRankSweep} and of a
 * CLI --scan the poll window didn't finish. Walks pending scans oldest-first, collecting ready task results
 * ({@see TownRankScanner::collectPending()}) under ONE per-run task_get budget AND a wall-clock deadline
 * short of the job timeout, so a run stops cleanly and never gets killed; a scan finalizes `complete` once every town is collected, and one still pending past the expiry
 * window is closed as `partial` over what it has so it can never block the next sweep. Cross-tenant, so the
 * {@see SiteScope} is dropped.
 */
class IngestTownRankScans implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Scheduled every minute; unique for the job's own timeout so runs queue behind one another, never beside. */
    public int $uniqueFor = 300;

    public int $timeout = 280;   // a run stops at its deadline; ShouldBeUnique keeps the next minute's dispatch from stacking

    public int $tries = 1;

    /** Seconds kept back from the job timeout so a hung read and the finalize never race the kill. */
    private const DEADLINE_MARGIN_SECONDS = 100;   // one hung read can take ~95s (3 tries × 30s + backoff)

    public function __construct()
    {
        // Same lane as the Run button's posting job: with `launchpad.town_rank.queue` set (e.g. "high") and the
        // worker started `--queue=high,default`, collection never waits behind a publishing backlog.
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(TownRankScanner $scanner): void
    {
        $started = microtime(true);
        $deadline = $started + max(30, $this->timeout - self::DEADLINE_MARGIN_SECONDS);
        $budget = max(1, (int) config('launchpad.town_rank.ingest_batch', 1000));
        $expiryCutoff = Carbon::now()->subHours(max(1, (int) config('launchpad.town_rank.pending_expiry_hours', 24)));

        $pending = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('status', 'pending')
            ->orderBy('scanned_at')
            ->get();
        if ($pending->isEmpty()) {
            return;
        }

        $spent = 0;
        $finalized = 0;
        $expired = 0;
        foreach ($pending as $scan) {
            if ($budget > 0 && microtime(true) < $deadline) {
                $got = $scanner->collectPending($scan, $budget, $deadline);
                $budget -= $got;
                $spent += $got;
                $scan->refresh();
                if ($scan->status !== 'pending') {
                    $finalized++;
                }
            }
            if ($scan->status === 'pending' && $scan->scanned_at !== null && $scan->scanned_at->lt($expiryCutoff)) {
                $scanner->finalize($scan, 'partial');
                $expired++;
            }
        }

        Log::info('Town-rank collector: run finished.', [
            'pending_scans' => $pending->count(),
            'task_gets' => $spent,
            'finalized' => $finalized,
            'expired_partial' => $expired,
            'seconds' => round(microtime(true) - $started, 1),
            'stopped_at_deadline' => microtime(true) >= $deadline,
        ]);
    }
}
