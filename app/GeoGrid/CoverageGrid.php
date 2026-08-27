<?php

namespace App\GeoGrid;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * The town point-source for coverage-mode geo-grid scanning: every municipality in the location's served
 * COUNTIES becomes one scan point at its centroid. This measures the WHOLE county — not just the towns we've
 * built pages for — because Google ranks the business across the whole area regardless of what we've
 * published; the report then shows where visibility is weak, i.e. where to build next.
 *
 * A municipality belongs to the scan when its Census GEOID prefixes with one of the location's county GEOIDs
 * (home county + owner-selected `county_geoids`), OR when it was explicitly assigned to the location
 * (`source_location_ids`) — the latter also covers a location whose counties aren't resolved yet, so scanning
 * never silently stops. Only geocoded towns (lat+lng) can be scanned. Operator context crosses tenants, so
 * the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class CoverageGrid
{
    /**
     * The location's county towns as scan points, population-descending (highest-value first).
     *
     * @return list<array{coverage_area_id: string, label: string, lat: float, lng: float, population: int}>
     */
    public function pointsFor(Location $location): array
    {
        $counties = $this->countyGeoIds($location);

        return CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get()
            ->filter(fn (CoverageArea $area): bool => $this->inScan($area, $location, $counties))
            ->sortByDesc(fn (CoverageArea $area): int => (int) ($area->population ?? 0))
            ->map(fn (CoverageArea $area): array => [
                'coverage_area_id' => (string) $area->id,
                'label' => (string) $area->name,
                'lat' => (float) $area->lat,
                'lng' => (float) $area->lng,
                'population' => (int) ($area->population ?? 0),
            ])
            ->values()
            ->all();
    }

    /** Town count for a location — for the scan command's cost estimate (requests = towns × keywords). */
    public function count(Location $location): int
    {
        return count($this->pointsFor($location));
    }

    /**
     * The 5-digit county GEOIDs the location serves — its home county plus any owner-selected counties.
     *
     * @return list<string>
     */
    private function countyGeoIds(Location $location): array
    {
        return collect([$location->home_county_geoid])
            ->merge(is_array($location->county_geoids) ? $location->county_geoids : [])
            ->map(fn ($geoId): string => trim((string) $geoId))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * In the scan when the municipality sits in one of the location's counties (GEOID prefix) OR was
     * explicitly assigned to it (the fallback that keeps a not-yet-countied location scanning).
     *
     * @param  list<string>  $counties
     */
    private function inScan(CoverageArea $area, Location $location, array $counties): bool
    {
        $geoId = (string) $area->geo_id;
        foreach ($counties as $county) {
            if ($county !== '' && str_starts_with($geoId, $county)) {
                return true;
            }
        }

        return in_array(
            (string) $location->id,
            array_map('strval', is_array($area->source_location_ids) ? $area->source_location_ids : []),
            true,
        );
    }
}
