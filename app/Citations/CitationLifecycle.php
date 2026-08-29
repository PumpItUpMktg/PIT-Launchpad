<?php

namespace App\Citations;

use App\Enums\CitationEventType;
use App\Enums\CitationSource;
use App\Enums\CitationState;
use App\Models\CitationEvent;
use App\Models\CitationFoundDomain;
use App\Models\CitationStatus;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * The submit→verify lifecycle for a citation (§ Citations, PR7).
 *
 * A work order goes out; a VA (or an operator's manual-submit) reports the listing done → `submitted`. The next
 * scan pass looks for it: found → `live` (a new listing) or `fixed` (a correction); not found → another
 * verification cycle, and after the threshold the citation flips to `unverified` for a human to chase. A
 * citation that keeps coming back in work orders without ever resolving flips to `stalled`. Every transition is
 * written to the append-only event ledger. The scanner leaves these states alone (isLifecycleProtected) so a
 * routine scan never clobbers human-owned progress.
 */
final class CitationLifecycle
{
    /** Record that a VA / operator submitted the listing. Resets the verification clock. */
    public function submit(CitationStatus $status, CitationSource $source = CitationSource::Va): void
    {
        $from = $status->state;
        $status->forceFill([
            'state' => CitationState::Submitted,
            'source' => $source,
            'submitted_at' => Carbon::now(),
            'verification_cycles' => 0,
        ])->save();

        $this->emit($status, CitationEventType::Submitted, $from, CitationState::Submitted);
    }

    /** Record that the directory / VA rejected the submission. */
    public function reject(CitationStatus $status, ?string $reason = null): void
    {
        $from = $status->state;
        $status->forceFill(['state' => CitationState::Rejected, 'reject_reason' => $reason])->save();

        $this->emit($status, CitationEventType::Rejected, $from, CitationState::Rejected, $reason !== null ? ['reason' => $reason] : []);
    }

    /**
     * Verify every awaiting-verification citation at a location against this scan's found domains: present →
     * live/fixed; absent → another cycle, or unverified at the threshold. Returns the outcome tally.
     *
     * @return array{verified: int, pending: int, unverified: int}
     */
    public function verify(Location $location, Carbon $scanStartedAt): array
    {
        $threshold = max(1, (int) config('launchpad.citations.verification_cycle_threshold', 3));
        $tally = ['verified' => 0, 'pending' => 0, 'unverified' => 0];

        $awaiting = CitationStatus::query()
            ->where('location_id', $location->id)
            ->whereIn('state', [CitationState::Submitted->value, CitationState::PendingVerification->value])
            ->get();

        foreach ($awaiting as $status) {
            if ($this->seenThisScan($status, $scanStartedAt)) {
                $from = $status->state;
                $to = ($status->mismatch_fields !== null && $status->mismatch_fields !== [])
                    ? CitationState::Fixed
                    : CitationState::Live;
                $status->forceFill(['state' => $to, 'verification_cycles' => 0])->save();
                $this->emit($status, CitationEventType::Verified, $from, $to);
                $tally['verified']++;

                continue;
            }

            $cycles = (int) $status->verification_cycles + 1;
            if ($cycles >= $threshold) {
                $from = $status->state;
                $status->forceFill(['state' => CitationState::Unverified, 'verification_cycles' => $cycles])->save();
                $this->emit($status, CitationEventType::Unverified, $from, CitationState::Unverified, ['cycles' => $cycles]);
                $tally['unverified']++;
            } else {
                $status->forceFill(['state' => CitationState::PendingVerification, 'verification_cycles' => $cycles])->save();
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

        if ($count >= $threshold && in_array($status->state, [CitationState::NotListed, CitationState::NeedsFix], true)) {
            $from = $status->state;
            $status->forceFill(['state' => CitationState::Stalled])->save();
            $this->emit($status, CitationEventType::Stalled, $from, CitationState::Stalled, ['work_orders' => $count]);
        }
    }

    /** Presence this scan: a matched found-domain row for the directory refreshed at/after the scan start. */
    private function seenThisScan(CitationStatus $status, Carbon $scanStartedAt): bool
    {
        return CitationFoundDomain::query()
            ->where('location_id', $status->location_id)
            ->where('directory_id', $status->directory_id)
            ->where('last_seen_at', '>=', $scanStartedAt)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function emit(CitationStatus $status, CitationEventType $type, CitationState $from, CitationState $to, array $meta = []): void
    {
        CitationEvent::query()->create([
            'site_id' => $status->site_id,
            'location_id' => $status->location_id,
            'directory_id' => $status->directory_id,
            'event_type' => $type,
            'from_state' => $from,
            'to_state' => $to,
            'occurred_at' => Carbon::now(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
