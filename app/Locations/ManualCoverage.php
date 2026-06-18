<?php

namespace App\Locations;

use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Owner-directed coverage: specific municipalities added to a location beyond its radius
 * (a targeted town outside the circle, or a gap town between non-intersecting locations).
 * A manual add is the strongest signal the town earns a page, so it persists across
 * recompute (radius rows are rebuilt; manual rows are not — see {@see CoverageWriter}) and
 * is flagged a priority location-page candidate. Stored as `coverage_areas.source=manual`,
 * GEOID-keyed so a manual add already inside a radius dedupes (counted once).
 */
final class ManualCoverage
{
    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * @return list<Municipality>
     */
    public function search(string $query): array
    {
        return $this->gazetteer->byName($query);
    }

    public function add(Site $site, Location $location, Municipality $municipality): CoverageArea
    {
        // Upsert on (site_id, geo_id) — a town already covered by a radius is UPGRADED to
        // manual (it's the unique key; a separate row would violate it). Removing the
        // manual flag later lets the radius recompute reclaim it as auto coverage.
        $existing = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('geo_id', $municipality->geoId)
            ->first();

        $ids = $existing !== null && is_array($existing->source_location_ids) ? $existing->source_location_ids : [];
        $attributes = [
            'name' => $municipality->name,
            'type' => $municipality->type,
            'state' => $municipality->state,
            'lat' => $municipality->lat,
            'lng' => $municipality->lng,
            'source_location_ids' => array_values(array_unique([...$ids, $location->id])),
            'page_selected' => true, // a manual add is a priority page — selected by default
            'source' => 'manual',
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return CoverageArea::create([
            'site_id' => $site->id,
            'geo_id' => $municipality->geoId,
            'distance_miles' => null,
            ...$attributes,
        ]);
    }

    public function remove(Site $site, string $geoId): void
    {
        CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('geo_id', $geoId)
            ->where('source', 'manual')
            ->delete();
    }

    /**
     * @return Collection<int, CoverageArea>
     */
    public function forSite(Site $site): Collection
    {
        return CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('source', 'manual')
            ->get();
    }
}
