<?php

namespace App\Jobs;

use App\Citations\CitationDiffer;
use App\Citations\CitationLifecycle;
use App\Citations\CitationReconciler;
use App\Citations\CitationScanner;
use App\Citations\LocalPresenceScore;
use App\Citations\ScanRunRecorder;
use App\Models\CitationStatus;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\CurrentSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the monthly citation scan for one location on the queue (§ Citations). Idempotent by
 * (location, directory) — a re-scan updates the same status rows. Sets the tenant scope so every
 * site-scoped read/write in the scanner resolves to the location's own site.
 *
 * The run is wrapped in a {@see ScanRunRecorder} + {@see CitationDiffer} (PR4): the location's states are
 * captured BEFORE the scan mutates them, then diffed after, so the run records what changed this month
 * (new / fixed / regressed / lost) and the event ledger gains a row per transition.
 */
class RunCitationScan implements ShouldQueue
{
    use Queueable;

    /** DataForSEO round-trips take time; cap the whole scan generously. */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly string $locationId,
        public readonly bool $sweepSharedNumbers = true,
        public readonly string $trigger = 'scheduled',
    ) {}

    public function handle(
        CitationScanner $scanner,
        CitationReconciler $reconciler,
        LocalPresenceScore $score,
        ScanRunRecorder $recorder,
        CitationDiffer $differ,
        CitationLifecycle $lifecycle,
    ): void {
        $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($this->locationId);
        if ($location === null) {
            return;
        }

        CurrentSite::set((string) $location->site_id);

        // Capture the pre-scan state per directory so the diff can see what actually changed.
        $prior = CitationStatus::query()
            ->where('location_id', $location->id)
            ->get()
            ->mapWithKeys(fn (CitationStatus $s): array => [(string) $s->directory_id => $s->state])
            ->all();

        $run = $recorder->open($location, $this->trigger);

        $scanner->scanLocation($location);

        if ($this->sweepSharedNumbers) {
            $scanner->sweepSharedNumbers((string) $location->site_id);
        }

        // Turn the applicable-but-unfound directories into tracked gaps.
        $reconciler->reconcile($location);

        // Advance any awaiting-verification citations against what this pass actually found.
        $lifecycle->verify($location, $run->started_at);

        // Snapshot the resulting score after lifecycle + reconcile settle.
        $scoreResult = $score->snapshot($location);

        // Diff pre vs post, write the event ledger, and close the run with the buckets + score.
        $buckets = $differ->record($location, $run, $prior);
        $recorder->close($run, $location, $buckets, $scoreResult['score']);
    }
}
