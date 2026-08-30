<?php

namespace App\Jobs;

use App\Citations\PlacesRefresher;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\CurrentSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-pulls one location's GBP from Places on the queue and refreshes the Location + NAP (§ Citations). Sets the
 * tenant scope so the NAP hydrator's writes resolve to the location's own site. Idempotent — a re-run with
 * unchanged GBP data is a no-op. Bounded retry: the Places call is a network round-trip.
 */
class RefreshLocationPlaces implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public readonly string $locationId) {}

    public function handle(PlacesRefresher $refresher): void
    {
        $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($this->locationId);
        if ($location === null) {
            return;
        }

        CurrentSite::set((string) $location->site_id);

        $refresher->refresh($location);
    }
}
