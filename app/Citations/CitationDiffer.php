<?php

namespace App\Citations;

use App\Enums\CitationEventType;
use App\Enums\CitationPresence;
use App\Models\CitationEvent;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Compares a location's citation PRESENCE before and after a scan (§ Citations) and writes the resulting
 * history. Each directory that crossed the correct/not-correct boundary becomes one append-only
 * {@see CitationEvent}; the counts are the monthly diff buckets (new / fixed / regressed / lost).
 *
 * The differ works purely on the presence axis — the scanner's own output. The lifecycle axis
 * (submit/verify/reject/stall) writes its own events via {@see CitationLifecycle}, so there is no longer a
 * shared column for two writers to referee: the double-eventing guard and scan-based stall tracking the old
 * single-enum model needed are gone.
 */
final class CitationDiffer
{
    /**
     * Emit events for every coverage-boundary presence change since `$prior` and return the diff buckets.
     *
     * @param  array<string, CitationPresence>  $prior  directory_id => presence captured BEFORE the scan
     * @return array{new: int, fixed: int, regressed: int, lost: int}
     */
    public function record(Location $location, CitationScanRun $run, array $prior): array
    {
        $now = Carbon::now();
        $buckets = ['new' => 0, 'fixed' => 0, 'regressed' => 0, 'lost' => 0];

        $current = CitationStatus::query()->where('location_id', $location->id)->get();

        foreach ($current as $status) {
            $from = $prior[(string) $status->directory_id] ?? null;
            $type = $this->classify($from, $status->presence);
            if ($type !== null) {
                $this->emit($status, $run, $type, $from, $status->presence, $now);
                $buckets[$this->bucket($type)]++;
            }
        }

        return $buckets;
    }

    /** The coverage-boundary transition (correct listing = coverage), or null when nothing crossed it. */
    private function classify(?CitationPresence $from, CitationPresence $to): ?CitationEventType
    {
        $wasCorrect = $from === CitationPresence::PresentMatch;
        $isCorrect = $to === CitationPresence::PresentMatch;

        if (! $wasCorrect && $isCorrect) {
            return $from === CitationPresence::PresentMismatch ? CitationEventType::Fixed : CitationEventType::Discovered;
        }
        if ($wasCorrect && ! $isCorrect) {
            return $to === CitationPresence::PresentMismatch ? CitationEventType::Regressed : CitationEventType::Lost;
        }

        return null;
    }

    /** @return 'new'|'fixed'|'regressed'|'lost' */
    private function bucket(CitationEventType $type): string
    {
        return match ($type) {
            CitationEventType::Discovered => 'new',
            CitationEventType::Fixed => 'fixed',
            CitationEventType::Regressed => 'regressed',
            CitationEventType::Lost => 'lost',
            default => 'new', // unreachable — classify() only returns the four diff types above
        };
    }

    private function emit(CitationStatus $status, CitationScanRun $run, CitationEventType $type, ?CitationPresence $from, CitationPresence $to, Carbon $now): void
    {
        CitationEvent::query()->create([
            'site_id' => $status->site_id,
            'location_id' => $status->location_id,
            'directory_id' => $status->directory_id,
            'citation_scan_run_id' => $run->id,
            'event_type' => $type,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'occurred_at' => $now,
        ]);
    }
}
