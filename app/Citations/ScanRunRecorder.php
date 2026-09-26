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
     * Close a run that blew up (a DataForSEO error, a timeout) with the reason, so the board can say "scan
     * failed" instead of "scanning…" forever. Idempotent — an already-closed run is left alone.
     */
    public function fail(CitationScanRun $run, string $error): void
    {
        if ($run->finished_at !== null) {
            return;
        }
        $run->forceFill(['finished_at' => Carbon::now(), 'error' => mb_substr(trim($error), 0, 1000)])->save();
    }

    /** Close every still-open run for a location as failed (a worker that died mid-scan leaves one behind). */
    public function failOpenRuns(string $locationId, string $error): void
    {
        CitationScanRun::query()->withoutGlobalScopes()
            ->where('location_id', $locationId)->whereNull('finished_at')
            ->get()
            ->each(fn (CitationScanRun $run) => $this->fail($run, $error));
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
