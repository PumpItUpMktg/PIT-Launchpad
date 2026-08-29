<?php

namespace App\Citations;

use App\Enums\CitationPresence;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Opens and closes a {@see CitationScanRun} around a location's scan (§ Citations, PR4). `open()` stamps the
 * start; `close()` captures the coverage snapshot (covered / needs-fix / not-listed) plus the diff buckets and
 * score, and marks the run finished — one durable "here's the state of this location's citations this month"
 * record the operator's what-changed view reads.
 */
final class ScanRunRecorder
{
    public function open(Location $location, string $trigger = 'scheduled'): CitationScanRun
    {
        return CitationScanRun::query()->create([
            'site_id' => $location->site_id,
            'location_id' => $location->id,
            'trigger' => $trigger,
            'started_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array{new: int, fixed: int, regressed: int, lost: int}  $buckets
     */
    public function close(CitationScanRun $run, Location $location, array $buckets, ?int $score): void
    {
        $statuses = CitationStatus::query()->where('location_id', $location->id)->get();

        $covered = $statuses->filter(fn (CitationStatus $s): bool => $s->presence === CitationPresence::PresentMatch)->count();
        $needsFix = $statuses->filter(fn (CitationStatus $s): bool => $s->presence === CitationPresence::PresentMismatch)->count();
        $notListed = $statuses->filter(fn (CitationStatus $s): bool => $s->presence === CitationPresence::Absent)->count();

        $run->forceFill([
            'finished_at' => Carbon::now(),
            'directories_evaluated' => $statuses->count(),
            'covered_count' => $covered,
            'needs_fix_count' => $needsFix,
            'not_listed_count' => $notListed,
            'score' => $score,
            'new_count' => $buckets['new'],
            'fixed_count' => $buckets['fixed'],
            'regressed_count' => $buckets['regressed'],
            'lost_count' => $buckets['lost'],
        ])->save();
    }
}
