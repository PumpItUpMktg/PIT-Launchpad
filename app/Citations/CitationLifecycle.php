<?php

namespace App\Citations;

use App\Enums\CitationEventType;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Models\CitationEvent;
use App\Models\CitationStatus;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Drives the lifecycle axis of a citation (§ Citations) — the "what work have we done" half, completely
 * separate from the scanner-owned presence axis.
 *
 * A work order goes out; a VA (or an operator's manual-submit) reports the listing done → `submitted`. The next
 * scan updates presence independently; this verifier reads that presence: found this pass → `verified`; not
 * found → another cycle, and past the threshold → `stalled`. A citation issued in too many work orders without
 * resolving also `stalled`s. Because the two axes are separate columns, a scan never clobbers this progress and
 * this never clobbers presence — a `verified` listing that later scans `present_mismatch` keeps both truths.
 */
final class CitationLifecycle
{
    /** Record that a VA / operator submitted the listing. Resets the verification clock. */
    public function submit(CitationStatus $status, CitationSource $source = CitationSource::Va): void
    {
        $from = $status->lifecycle;
        $status->forceFill([
            'lifecycle' => CitationLifecycleState::Submitted,
            'source' => $source,
            'submitted_at' => Carbon::now(),
            'verification_cycles' => 0,
        ])->save();

        $this->emit($status, CitationEventType::Submitted, $from, CitationLifecycleState::Submitted);
    }

    /** Record that the directory / VA rejected the submission. */
    public function reject(CitationStatus $status, ?string $reason = null): void
    {
        $from = $status->lifecycle;
        $status->forceFill(['lifecycle' => CitationLifecycleState::Rejected, 'reject_reason' => $reason])->save();

        $this->emit($status, CitationEventType::Rejected, $from, CitationLifecycleState::Rejected, $reason !== null ? ['reason' => $reason] : []);
    }

    /**
     * Verify every awaiting-verification citation at a location against what the just-finished scan found:
     * present this pass → verified; not found → another cycle, or stalled at the threshold.
     *
     * @return array{verified: int, pending: int, stalled: int}
     */
    public function verify(Location $location, Carbon $scanStartedAt): array
    {
        $threshold = max(1, (int) config('launchpad.citations.verification_cycle_threshold', 3));
        $tally = ['verified' => 0, 'pending' => 0, 'stalled' => 0];

        $awaiting = CitationStatus::query()
            ->where('location_id', $location->id)
            ->where('lifecycle', CitationLifecycleState::Submitted->value)
            ->get();

        foreach ($awaiting as $status) {
            if ($this->foundThisScan($status, $scanStartedAt)) {
                $status->forceFill(['lifecycle' => CitationLifecycleState::Verified, 'verification_cycles' => 0])->save();
                $this->emit($status, CitationEventType::Verified, CitationLifecycleState::Submitted, CitationLifecycleState::Verified);
                $tally['verified']++;

                continue;
            }

            $cycles = (int) $status->verification_cycles + 1;
            if ($cycles >= $threshold) {
                $status->forceFill(['lifecycle' => CitationLifecycleState::Stalled, 'verification_cycles' => $cycles])->save();
                $this->emit($status, CitationEventType::Stalled, CitationLifecycleState::Submitted, CitationLifecycleState::Stalled, ['cycles' => $cycles]);
                $tally['stalled']++;
            } else {
                $status->forceFill(['verification_cycles' => $cycles])->save();
                $tally['pending']++;
            }
        }

        return $tally;
    }

    /**
     * Record that a citation was issued in a work order; a citation issued too many times without resolving
     * flips to `stalled`.
     */
    public function recordWorkOrderIssued(CitationStatus $status): void
    {
        $threshold = max(1, (int) config('launchpad.citations.work_order_stall_threshold', 3));
        $count = (int) $status->work_order_count + 1;
        $status->forceFill(['work_order_count' => $count])->save();

        $unresolved = $status->presence !== CitationPresence::PresentMatch
            && ! in_array($status->lifecycle, [CitationLifecycleState::Verified, CitationLifecycleState::Rejected], true);

        if ($count >= $threshold && $unresolved) {
            $from = $status->lifecycle;
            $status->forceFill(['lifecycle' => CitationLifecycleState::Stalled])->save();
            $this->emit($status, CitationEventType::Stalled, $from, CitationLifecycleState::Stalled, ['work_orders' => $count]);
        }
    }

    /** The scan refreshed this citation's presence to a real listing on this pass. */
    private function foundThisScan(CitationStatus $status, Carbon $scanStartedAt): bool
    {
        return $status->presence->isPresent()
            && $status->last_scanned_at !== null
            && $status->last_scanned_at->greaterThanOrEqualTo($scanStartedAt);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function emit(CitationStatus $status, CitationEventType $type, CitationLifecycleState $from, CitationLifecycleState $to, array $meta = []): void
    {
        CitationEvent::query()->create([
            'site_id' => $status->site_id,
            'location_id' => $status->location_id,
            'directory_id' => $status->directory_id,
            'event_type' => $type,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'occurred_at' => Carbon::now(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
