<?php

namespace App\Jobs;

use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Geocode a base location off the web request: the consolidated add-flow dispatches this
 * the moment a location is created, so the operator never sees a geocode step — just a
 * quiet "located" when it lands. Address → Census Geocoder → point, then the point's home
 * county (TIGERweb) is resolved and default-selected (county-based coverage). A miss flags
 * `geocode_failed` so the surface can offer a manual override (only then). Idempotent.
 */
class GeocodeLocation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $locationId) {}

    public function handle(Geocoder $geocoder, MunicipalityGazetteer $gazetteer): void
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->find($this->locationId);
        if ($location === null) {
            return;
        }

        $lat = $location->lat === null ? null : (float) $location->lat;
        $lng = $location->lng === null ? null : (float) $location->lng;
        $fill = [];

        // Geocode only when the point is missing — a listing-supplied point is kept as-is;
        // we still resolve the home county for it below.
        if ($lat === null || $lng === null) {
            $address = trim((string) $location->address);
            $result = $address === '' ? null : $geocoder->geocode($address);

            if ($result === null) {
                $location->forceFill(['geocode_failed' => true])->save();

                return;
            }

            $lat = $result->lat;
            $lng = $result->lng;
            $fill['address'] = $result->matchedAddress;
        }

        // Resolve the home county; default-select it if the owner hasn't chosen counties yet.
        $county = $gazetteer->countyAt($lat, $lng);
        $selected = is_array($location->county_geoids) ? $location->county_geoids : [];
        if ($county !== null && $selected === []) {
            $selected = [$county->geoId];
        }

        $location->forceFill([
            ...$fill,
            'lat' => $lat,
            'lng' => $lng,
            'geocode_failed' => false,
            'home_county_geoid' => $county?->geoId,
            'county_geoids' => $selected,
        ])->save();
    }
}
