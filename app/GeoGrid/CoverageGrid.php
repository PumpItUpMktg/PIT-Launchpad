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
     * @return list<array{coverage_area_id: string, geo_id: string, label: string, lat: float, lng: float, population: int}>
     */
    public function pointsFor(Location $location): array
    {
        return $this->pointsForMany([$location])[(string) $location->id] ?? [];
    }

    /**
     * {@see pointsFor()} for several locations at once, keyed by location id — the site's coverage areas are
     * loaded ONCE and shared, so a whole-site walk (Town Rank's town list) costs one query rather than one
     * full area load per location. Each location's list is identical to what {@see pointsFor()} returns.
     *
     * @param  iterable<Location>  $locations
     * @return array<string, list<array{coverage_area_id: string, geo_id: string, label: string, lat: float, lng: float, population: int}>>
     */
    public function pointsForMany(iterable $locations): array
    {
        /** @var array<string, list<Location>> $bySite */
        $bySite = [];
        foreach ($locations as $location) {
            $bySite[(string) $location->site_id][] = $location;
        }

        $result = [];
        foreach ($bySite as $siteId => $siteLocations) {
            // Rows pre-shaped once: the point payload plus the two things inScan() needs (GEOID + assigned ids).
            $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->whereNotNull('lat')->whereNotNull('lng')
                ->get()
                // TIGER's pseudo-subdivision ("County subdivisions not defined") is water/unorganized area, not a town.
                ->filter(fn (CoverageArea $area): bool => preg_match('/not defined/i', (string) $area->name) !== 1)
                ->map(fn (CoverageArea $area): array => [
                    'point' => [
                        'coverage_area_id' => (string) $area->id,
                        // The town's durable identity: the row id above is replaced on every coverage rebuild.
                        'geo_id' => (string) $area->geo_id,
                        'label' => (string) $area->name,
                        'lat' => (float) $area->lat,
                        'lng' => (float) $area->lng,
                        'population' => (int) ($area->population ?? 0),
                    ],
                    'geo_id' => (string) $area->geo_id,
                    'assigned' => array_map('strval', is_array($area->source_location_ids) ? $area->source_location_ids : []),
                ])
                ->values()
                ->all();

            foreach ($siteLocations as $location) {
                $counties = $this->countyGeoIds($location);
                $points = [];
                foreach ($areas as $area) {
                    if ($this->inScan($area['geo_id'], $area['assigned'], $location, $counties)) {
                        $points[] = $area['point'];
                    }
                }
                usort($points, fn (array $a, array $b): int => $b['population'] <=> $a['population']);
                $result[(string) $location->id] = $points;
            }
        }

        return $result;
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
     * @param  list<string>  $assigned  the area's `source_location_ids`, as strings
     * @param  list<string>  $counties
     */
    private function inScan(string $geoId, array $assigned, Location $location, array $counties): bool
    {
        foreach ($counties as $county) {
            if ($county !== '' && str_starts_with($geoId, $county)) {
                return true;
            }
        }

        return in_array((string) $location->id, $assigned, true);
    }
}
