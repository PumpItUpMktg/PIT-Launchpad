<?php

namespace App\Citations;

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Enums\MultiLocationPolicy;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Reconciles a location's scan results against its APPLICABLE directory set (§ Citations, PR3): every
 * applicable directory the scan didn't already record becomes a gap row. This is what turns "we didn't find
 * a listing" into a tracked `not_listed` (→ a work order in PR6).
 *
 * Multi-location safety: for a `one_per_business` directory a sibling already satisfies, the gap is recorded
 * as `covered_by_sibling`, not `not_listed` — the business needs one listing, not one per location, so we
 * never manufacture a duplicate work order the siblings would fight over.
 */
final class CitationReconciler
{
    public function __construct(private readonly CitationApplicability $applicability = new CitationApplicability) {}

    /** Write gap rows for applicable directories with no scan status. Returns the number of rows written. */
    public function reconcile(Location $location): int
    {
        $applicable = $this->applicability->forLocation($location);
        $existing = CitationStatus::query()
            ->where('location_id', $location->id)
            ->pluck('directory_id')
            ->all();
        $existing = array_flip(array_map('strval', $existing));

        $now = Carbon::now();
        $written = 0;
        foreach ($applicable as $dir) {
            if (isset($existing[(string) $dir->id])) {
                continue; // The scan already recorded this listing (found or otherwise).
            }

            // An applicable directory the scan didn't find is Absent. For a one_per_business directory a
            // sibling already covers, mark covered_by_sibling so it's never turned into a duplicate work order.
            $coveredBySibling = $dir->multi_location_policy === MultiLocationPolicy::OnePerBusiness
                && $this->siblingCovers($location, $dir);

            CitationStatus::query()->create([
                'site_id' => $location->site_id,
                'location_id' => $location->id,
                'directory_id' => $dir->id,
                'presence' => CitationPresence::Absent,
                'covered_by_sibling' => $coveredBySibling,
                'attributed_location_id' => $coveredBySibling ? $location->id : null,
                'source' => CitationSource::Unknown,
                'first_seen_at' => $now,
                'last_scanned_at' => $now,
            ]);
            $written++;
        }

        return $written;
    }

    private function siblingCovers(Location $location, Directory $dir): bool
    {
        return CitationStatus::query()
            ->where('site_id', $location->site_id)
            ->where('directory_id', $dir->id)
            ->where('location_id', '!=', $location->id)
            ->get()
            ->contains(fn (CitationStatus $s): bool => $s->presence === CitationPresence::PresentMatch
                || $s->lifecycle === CitationLifecycleState::Verified);
    }
}
