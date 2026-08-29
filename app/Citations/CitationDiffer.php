<?php

namespace App\Citations;

use App\Enums\CitationEventType;
use App\Enums\CitationState;
use App\Models\CitationEvent;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Compares a location's citation states before and after a scan (§ Citations, PR4) and writes the resulting
 * history. Each directory that crossed the covered/not-covered boundary becomes one append-only
 * {@see CitationEvent}; the counts are the monthly diff buckets (new / fixed / regressed / lost). Separately it
 * tracks how many consecutive scans each gap has survived and raises a `stalled` event once one crosses the
 * threshold — the escalation signal for a directory that isn't getting fixed.
 */
final class CitationDiffer
{
    /** States that represent open work (a listing we owe but don't yet have right). */
    private const WORK_ORDER_STATES = [
        CitationState::NotListed,
        CitationState::NeedsFix,
        CitationState::Submitted,
        CitationState::PendingVerification,
    ];

    /**
     * Emit events for every covered-boundary change since `$prior`, track stalls, and return the diff buckets.
     *
     * @param  array<string, CitationState>  $prior  directory_id => state captured BEFORE the scan mutated statuses
     * @return array{new: int, fixed: int, regressed: int, lost: int}
     */
    public function record(Location $location, CitationScanRun $run, array $prior): array
    {
        $threshold = max(1, (int) config('launchpad.citations.stalled_scan_threshold', 3));
        $now = Carbon::now();
        $buckets = ['new' => 0, 'fixed' => 0, 'regressed' => 0, 'lost' => 0];

        $current = CitationStatus::query()->where('location_id', $location->id)->get();

        foreach ($current as $status) {
            $from = $prior[(string) $status->directory_id] ?? null;
            $to = $status->state;

            $type = $this->classify($from, $to);
            if ($type !== null) {
                $this->emit($status, $run, $type, $from, $to, $now);
                $buckets[$this->bucket($type)]++;
            }

            $this->trackStalled($status, $run, $threshold, $now);
        }

        return $buckets;
    }

    /** The covered-boundary transition, or null when nothing crossed it. */
    private function classify(?CitationState $from, CitationState $to): ?CitationEventType
    {
        $wasCovered = $from?->isCovered() ?? false;
        $isCovered = $to->isCovered();

        if (! $wasCovered && $isCovered) {
            return $from === CitationState::NeedsFix ? CitationEventType::Fixed : CitationEventType::Discovered;
        }
        if ($wasCovered && ! $isCovered) {
            return $to === CitationState::NeedsFix ? CitationEventType::Regressed : CitationEventType::Lost;
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
            CitationEventType::Stalled => 'new', // unreachable — stalled is emitted separately
        };
    }

    /**
     * Increment the unresolved-scan counter while a gap persists (reset it when covered), and raise a single
     * `stalled` event the scan it first crosses the threshold.
     */
    private function trackStalled(CitationStatus $status, CitationScanRun $run, int $threshold, Carbon $now): void
    {
        if (in_array($status->state, self::WORK_ORDER_STATES, true)) {
            $count = (int) $status->unresolved_scans + 1;
            $status->forceFill(['unresolved_scans' => $count])->save();

            if ($count === $threshold) {
                $this->emit($status, $run, CitationEventType::Stalled, $status->state, $status->state, $now, ['unresolved_scans' => $count]);
            }

            return;
        }

        if ((int) $status->unresolved_scans !== 0) {
            $status->forceFill(['unresolved_scans' => 0])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function emit(CitationStatus $status, CitationScanRun $run, CitationEventType $type, ?CitationState $from, CitationState $to, Carbon $now, array $meta = []): void
    {
        CitationEvent::query()->create([
            'site_id' => $status->site_id,
            'location_id' => $status->location_id,
            'directory_id' => $status->directory_id,
            'citation_scan_run_id' => $run->id,
            'event_type' => $type,
            'from_state' => $from,
            'to_state' => $to,
            'occurred_at' => $now,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
