<?php

namespace App\Jobs;

use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\GeoGridScanner;
use App\Models\GeoGridScan;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * The coverage-scan collection sweep — the async half of {@see RunCoverageScan}. A coverage scan over a whole
 * county is 100+ rate-limited DataForSEO task_get calls, which overruns a single job's timeout, so scans are
 * POSTED fast (pending) and COLLECTED here in bounded batches.
 *
 * Each run walks the pending coverage scans oldest-first and collects their ready task results
 * ({@see GeoGridScanner::collectPending()}), sharing ONE per-run task_get budget across all of them
 * (`geo_grid.ingest_batch`, ~12/min rate-limited) so this job itself stays well inside its timeout — the
 * remainder is picked up on the next scheduled run. A scan finalizes to `complete` once every point is
 * collected (then its aggregates are recomputed); one past the expiry window with tasks that never became
 * ready is finalized `partial` over what it has, so it can never sit pending forever.
 *
 * Cross-tenant sweep, so {@see SiteScope} is dropped.
 */
class IngestCoverageScans implements ShouldQueue
{
    use Queueable;

    /** Fits the shared task_get budget (rate-limited ~12/min) comfortably inside one run. */
    public int $timeout = 300;

    public int $tries = 1;

    public function handle(GeoGridScanner $scanner, GeoGridMetrics $metrics): void
    {
        $budget = max(1, (int) config('launchpad.geo_grid.ingest_batch', 40));
        $expiryHours = max(1, (int) config('launchpad.geo_grid.pending_expiry_hours', 24));
        $expiryCutoff = Carbon::now()->subHours($expiryHours);

        $pending = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('mode', 'coverage')
            ->where('status', 'pending')
            ->orderBy('scanned_at')
            ->get();

        foreach ($pending as $scan) {
            if ($budget > 0) {
                $budget -= $scanner->collectPending($scan, $budget);
                $scan->refresh();
            }

            if ($scan->status === 'complete') {
                $metrics->recompute($scan);

                continue;
            }

            // Never collected in full within the window — finalize over whatever we have so it stops blocking.
            if ($scan->scanned_at !== null && $scan->scanned_at->lt($expiryCutoff)) {
                $scan->forceFill(['status' => 'partial'])->save();
                $metrics->recompute($scan);
            }
        }
    }
}
