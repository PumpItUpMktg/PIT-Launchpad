<?php

namespace App\Citations\Ui;

use App\Citations\CitationApplicability;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * View-model for the tenant citation board (§ Citations UI, PR B) — one {@see LocationCitationCard} per
 * physical location, with each location's coverage broken down against its OWN eligible directory set (so the
 * board never shows a single tenant-level coverage figure; coverage is per listing). The scan state comes from
 * the scan-run ledger, so a card knows whether to show a coverage bar, a launch button, or a live progress bar.
 */
final class TenantCitationBoard
{
    public function __construct(private readonly CitationApplicability $applicability = new CitationApplicability) {}

    /**
     * @return list<LocationCitationCard>
     */
    public function forSite(Site $site): array
    {
        $locations = Location::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->get();

        $profiles = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
            ->whereIn('location_id', $locations->pluck('id')->all())->get()->keyBy('location_id');

        return $locations->map(function (Location $location) use ($profiles): LocationCitationCard {
            $profile = $profiles->get($location->id);
            $eligible = $this->applicability->forLocation($location);

            $statuses = CitationStatus::query()->withoutGlobalScope(SiteScope::class)
                ->where('location_id', $location->id)->get()->keyBy('directory_id');

            $live = $mismatch = $submitted = $missing = 0;
            foreach ($eligible as $dir) {
                $status = $statuses->get($dir->id);
                if ($status === null) {
                    $missing++;

                    continue;
                }
                if ($status->lifecycle === CitationLifecycleState::Submitted) {
                    $submitted++;
                } elseif ($status->presence === CitationPresence::PresentMatch || $status->covered_by_sibling) {
                    $live++;
                } elseif ($status->presence === CitationPresence::PresentMismatch) {
                    $mismatch++;
                } else {
                    $missing++;
                }
            }

            $eligibleCount = $eligible->count();

            return new LocationCitationCard(
                locationId: (string) $location->id,
                name: (string) $location->name,
                typeLabel: $location->is_storefront ? 'Storefront' : 'Service area',
                hasGbp: filled($location->gbp_url),
                hasNap: $profile !== null,
                nap: $profile !== null ? $this->napSnapshot($profile) : [],
                eligible: $eligibleCount,
                live: $live,
                mismatch: $mismatch,
                submitted: $submitted,
                missing: $missing,
                coveragePercent: $eligibleCount > 0 ? (int) round(100 * $live / $eligibleCount) : null,
                scanState: $this->scanState($location),
                lastScannedAt: $this->lastScannedAt($location),
                lastError: $this->lastError($location),
                unchecked: $this->unchecked($location),
            );
        })->all();
    }

    /**
     * The scan state from the run ledger. 'scanning' only while a run is open AND younger than the stale
     * threshold — an open run older than that is a worker that died, shown as failed, never as scanning
     * forever. 'failed' when the latest run closed with an error (a later clean run clears it).
     *
     * @return 'never'|'scanned'|'scanning'|'failed'
     */
    private function scanState(Location $location): string
    {
        $latest = CitationScanRun::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->latest('started_at')->first();
        if ($latest === null) {
            return 'never';
        }

        $staleMinutes = max(1, (int) config('launchpad.citations.stale_run_minutes', 30));
        if ($latest->finished_at === null) {
            return $latest->started_at->gt(Carbon::now()->subMinutes($staleMinutes)) ? 'scanning' : 'failed';
        }

        return $latest->error !== null ? 'failed' : 'scanned';
    }

    /** Directories the latest completed run could not check (a DataForSEO hiccup) — a rescan covers them. */
    private function unchecked(Location $location): int
    {
        $latest = CitationScanRun::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->latest('started_at')->first();
        $meta = is_array($latest?->meta) ? $latest->meta : [];

        return (int) ($meta['unchecked_directories'] ?? 0);
    }

    /** The latest run's failure reason (or a stale-run note), for the failed badge. */
    private function lastError(Location $location): ?string
    {
        $latest = CitationScanRun::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->latest('started_at')->first();
        if ($latest === null) {
            return null;
        }
        if ($latest->finished_at === null) {
            return 'The scan never finished — the queue worker may have stopped. Rescan.';
        }

        return $latest->error;
    }

    private function lastScannedAt(Location $location): ?Carbon
    {
        return CitationScanRun::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->latest('started_at')->first()?->started_at;
    }

    /** @return array<string, mixed> */
    private function napSnapshot(LocationNapProfile $profile): array
    {
        return [
            'business_name' => $profile->business_name,
            'address_1' => $profile->address_1,
            'city' => $profile->city,
            'state' => $profile->state,
            'postal' => $profile->postal,
            'phone_primary' => $profile->phone_primary,
        ];
    }
}
