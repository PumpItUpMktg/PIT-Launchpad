<?php

namespace App\Locations;

use App\Enums\MunicipalityType;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * The proximity coverage engine: each base Location's geocoded point + outer reach (miles) →
 * Census enumeration (places + MCDs) → exact Haversine distance filter → deduplicated
 * union across all base locations = the authoritative service-area coverage set for a
 * DISTANCE-drawn territory (an auto shop's 10–15 miles, not a county). Reads existing point
 * data; a base with no coordinates or no radius is skipped. County subdivisions are joined to
 * ACS population like the county engine so the drip's relevance score has its anchor.
 */
final class LocationCoverage
{
    public function __construct(
        private readonly MunicipalityGazetteer $gazetteer,
        private readonly ?CensusPopulation $population = null,
    ) {}

    /**
     * @param  int|null  $radiusOverride  apply this radius (miles) to every base for this
     *                                    run instead of each Location's saved radius — the
     *                                    CLI's --radius calibration path (no DB write).
     * @param  Collection<int, Location>|null  $locations  the bases to enumerate (default: all of
     *                                                     the site's; {@see SiteCoverage} passes the
     *                                                     proximity-mode ones)
     */
    public function coverage(Site $site, ?int $radiusOverride = null, ?Collection $locations = null): CoverageResult
    {
        $locations ??= Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        // Owner-directed (manual) coverage persists across recompute; merge it into the
        // union + the location it was added to (GEOID-keyed, sticky-manual on dedup).
        $manualByLocation = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('source', 'manual')
            ->get()
            ->groupBy(fn (CoverageArea $a) => is_array($a->source_location_ids) ? ($a->source_location_ids[0] ?? '') : '');

        $perBase = [];
        /** @var array<string, CoverageMunicipality> $union */
        $union = [];
        /** @var array<string, array<string, int>> $popCache  "ss:ccc" => geoId => population */
        $popCache = [];

        foreach ($locations as $location) {
            $lat = $location->lat === null ? null : (float) $location->lat;
            $lng = $location->lng === null ? null : (float) $location->lng;
            $radius = $radiusOverride !== null
                ? (float) $radiusOverride
                : ($location->coverage_radius === null ? 0.0 : (float) $location->coverage_radius);

            $found = [];

            if ($lat !== null && $lng !== null && $radius > 0.0) {
                foreach ($this->gazetteer->near($lat, $lng, $radius) as $m) {
                    if ($m->lat === null || $m->lng === null) {
                        continue;
                    }

                    $distance = Distance::miles($lat, $lng, $m->lat, $m->lng);
                    if ($distance > $radius) {
                        continue; // centroid outside the radius
                    }

                    $municipality = CoverageMunicipality::fromMunicipality($m, $distance, $location->id)
                        ->withPopulation($this->populationOf($m, $popCache));
                    $found[] = $municipality;

                    $union[$m->geoId] = isset($union[$m->geoId])
                        ? $union[$m->geoId]->mergedWith($location->id, $distance)
                        : $municipality;
                }

                usort($found, fn (CoverageMunicipality $a, CoverageMunicipality $b) => $a->distanceMiles <=> $b->distanceMiles);
            }

            foreach ($manualByLocation[$location->id] ?? [] as $row) {
                $manual = new CoverageMunicipality(
                    geoId: $row->geo_id,
                    name: $row->name,
                    type: $row->type,
                    state: $row->state,
                    lat: $row->lat === null ? null : (float) $row->lat,
                    lng: $row->lng === null ? null : (float) $row->lng,
                    distanceMiles: 0.0,
                    sourceLocationIds: [$location->id],
                    manual: true,
                );
                $found[] = $manual;
                $union[$row->geo_id] = isset($union[$row->geo_id])
                    ? $union[$row->geo_id]->mergedWith($location->id, 0.0, true)
                    : $manual;
            }

            if ($found !== []) {
                $perBase[] = new BaseCoverage($location->id, $location->name, $radius, $found);
            }
        }

        $unionList = array_values($union);
        usort($unionList, fn (CoverageMunicipality $a, CoverageMunicipality $b) => strcmp($a->name, $b->name));

        return new CoverageResult($perBase, $unionList);
    }

    /**
     * ACS population for a county subdivision (its GEOID carries the county: STATE(2)+COUNTY(3)+COUSUB(5));
     * a place GEOID has no county, and without a population client nothing is fetched.
     *
     * @param  array<string, array<string, int>>  $cache
     */
    private function populationOf(Municipality $m, array &$cache): ?int
    {
        if ($this->population === null || $m->type !== MunicipalityType::CountySubdivision || strlen($m->geoId) !== 10) {
            return null;
        }
        $stateFips = substr($m->geoId, 0, 2);
        $countyFips = substr($m->geoId, 2, 3);
        $pop = $cache["{$stateFips}:{$countyFips}"] ??= $this->population->forCounty($stateFips, $countyFips);

        return $pop[$m->geoId] ?? null;
    }
}
