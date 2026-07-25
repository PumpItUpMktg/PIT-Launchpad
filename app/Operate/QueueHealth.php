<?php

namespace App\Operate;

use Illuminate\Support\Facades\DB;

/**
 * The "is the background worker actually draining?" signal — read straight off the database queue
 * tables (this app runs the `database` queue driver). A publish is asynchronous: Repush/Approve
 * enqueue a job and the worker publishes it. When the worker is down, jobs pile up in `jobs` and
 * approved pages never leave "ready to publish" — so a growing, AGEING backlog (or failed jobs) is the
 * tell. Surfaced as an operator banner with the drain escape hatch; never blocks anything.
 */
final class QueueHealth
{
    /**
     * @return array{pending: int, oldest_minutes: int, failed: int, stalled: bool}
     */
    public function snapshot(int $stalledAfterMinutes = 5): array
    {
        $pending = (int) DB::table('jobs')->count();
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $oldestMinutes = $oldestAvailableAt !== null
            ? (int) floor(max(0, time() - (int) $oldestAvailableAt) / 60)
            : 0;
        $failed = (int) DB::table('failed_jobs')->count();

        return [
            'pending' => $pending,
            'oldest_minutes' => $oldestMinutes,
            'failed' => $failed,
            // Stalled = a backlog that has sat past the threshold (the worker isn't picking it up), or
            // any failed job. A small, fresh backlog is normal (the worker is mid-drain) → not stalled.
            'stalled' => ($pending > 0 && $oldestMinutes >= $stalledAfterMinutes) || $failed > 0,
        ];
    }
}
