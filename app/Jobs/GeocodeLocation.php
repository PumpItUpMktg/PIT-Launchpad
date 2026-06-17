<?php

namespace App\Jobs;

use App\Integrations\Census\Geocoder;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Geocode a base location off the web request: the consolidated add-flow dispatches this
 * the moment a location is created, so the operator never sees a geocode step — just a
 * quiet "located" when it lands. Address → Census Geocoder → point; a miss flags
 * `geocode_failed` so the surface can offer a manual override (only then). Idempotent and
 * retry-safe: re-running re-resolves from the current address.
 */
class GeocodeLocation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $locationId) {}

    public function handle(Geocoder $geocoder): void
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->find($this->locationId);
        if ($location === null) {
            return;
        }

        $address = trim((string) $location->address);
        $result = $address === '' ? null : $geocoder->geocode($address);

        if ($result === null) {
            $location->forceFill(['geocode_failed' => true])->save();

            return;
        }

        $location->forceFill([
            'address' => $result->matchedAddress,
            'lat' => $result->lat,
            'lng' => $result->lng,
            'geocode_failed' => false,
        ])->save();
    }
}
