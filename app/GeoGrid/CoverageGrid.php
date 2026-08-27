<?php

namespace App\GeoGrid;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operate\PhysicalLocations;

/**
 * The town point-source for coverage-mode geo-grid scanning: a location's served municipalities, each
 * becoming one scan point at its centroid. This is what makes the geo grid actionable — instead of an
 * abstract lattice, every point is a real town we target (a {@see CoverageArea}), so its rank joins directly
 * to that town's landing page, jobs, and reviews, and can be weighted by the town's population.
 *
 * A town belongs to a location when the location's id is in the area's `source_location_ids` (the same
 * authoritative membership {@see PhysicalLocations} reads). Only geocoded towns (lat+lng) can be
 * scanned; un-geocoded ones are skipped (reported by the caller). Operator context crosses tenants, so the
 * {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class CoverageGrid
{
    /**
     * The location's served, geocoded towns as scan points, population-descending (highest-value first).
     *
     * @return list<array{coverage_area_id: string, label: string, lat: float, lng: float, population: int}>
     */
    public function pointsFor(Location $location): array
    {
        return CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get()
            ->filter(fn (CoverageArea $area): bool => in_array(
                (string) $location->id,
                array_map('strval', is_array($area->source_location_ids) ? $area->source_location_ids : []),
                true,
            ))
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
}
