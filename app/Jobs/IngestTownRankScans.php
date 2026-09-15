<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\TownRankScan;
use App\TownRank\TownRankScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * The town-rank collection sweep (§ Town Rank, PR 3) — the async half of {@see RunTownRankSweep} and of a
 * CLI --scan the poll window didn't finish. Walks pending scans oldest-first, collecting ready task results
 * ({@see TownRankScanner::collectPending()}) under ONE per-run task_get budget so the job stays inside its
 * timeout; a scan finalizes `complete` once every town is collected, and one still pending past the expiry
 * window is closed as `partial` over what it has so it can never block the next sweep. Cross-tenant, so the
 * {@see SiteScope} is dropped.
 */
class IngestTownRankScans implements ShouldQueue
{
    use Queueable;

    public int $timeout = 280;   // under the five-minute schedule so a slow run can't overlap the next

    public int $tries = 1;

    public function handle(TownRankScanner $scanner): void
    {
        $budget = max(1, (int) config('launchpad.town_rank.ingest_batch', 40));
        $expiryCutoff = Carbon::now()->subHours(max(1, (int) config('launchpad.town_rank.pending_expiry_hours', 24)));

        $pending = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('status', 'pending')
            ->orderBy('scanned_at')
            ->get();

        foreach ($pending as $scan) {
            if ($budget > 0) {
                $budget -= $scanner->collectPending($scan, $budget);
                $scan->refresh();
            }
            if ($scan->status === 'pending' && $scan->scanned_at !== null && $scan->scanned_at->lt($expiryCutoff)) {
                $scanner->finalize($scan, 'partial');
            }
        }
    }
}
