<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\TownRankScan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The town-rank collection DISPATCHER (§ Town Rank): every minute, one {@see CollectTownRankScan} job per
 * pending scan. Collection itself moved into those per-scan jobs — they run side by side across the worker
 * lanes, read their tasks directly by id, and fail alone — so a whole-site burst (28 keywords × 2 modes ×
 * 722 towns) drains at the read ceiling instead of one serial pass a minute that any single bad town could
 * stall for every scan behind it. Each per-scan job is unique, so a scan already collecting is not queued
 * again. Cross-tenant, so the {@see SiteScope} is dropped.
 */
class IngestTownRankScans implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Scheduled every minute; unique so a minute's dispatch never stacks beside the previous one. */
    public int $uniqueFor = 120;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct()
    {
        // Same lane as the Run button's posting job: with `launchpad.town_rank.queue` set (e.g. "high") and the
        // worker started `--queue=high,default`, collection never waits behind a publishing backlog.
        $queue = config('launchpad.town_rank.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(): void
    {
        $pending = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('status', 'pending')
            ->orderBy('scanned_at')
            ->pluck('id');

        foreach ($pending as $scanId) {
            CollectTownRankScan::dispatch((string) $scanId);
        }

        if ($pending->isNotEmpty()) {
            Log::info('Town-rank dispatcher: per-scan collectors queued.', ['pending_scans' => $pending->count()]);
        }
    }
}
