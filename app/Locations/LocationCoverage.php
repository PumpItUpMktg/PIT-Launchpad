<?php

namespace App\Locations;

use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The headless coverage engine: each base Location's geocoded point + radius →
 * Census enumeration (places + MCDs) → exact Haversine distance filter → deduplicated
 * union across all base locations = the authoritative service-area coverage set. This
 * is the Phase-3 dependency. Reads existing point data (lat/lng captured by the
 * Places/GBP import); a base with no coordinates or no radius is skipped.
 */
final class LocationCoverage
{
    public function __construct(
        private readonly MunicipalityGazetteer $gazetteer,
    ) {}

    /**
     * @param  int|null  $radiusOverride  apply this radius (miles) to every base for this
     *                                    run instead of each Location's saved radius — the
     *                                    CLI's --radius calibration path (no DB write).
     */
    public function coverage(Site $site, ?int $radiusOverride = null): CoverageResult
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        $perBase = [];
        /** @var array<string, CoverageMunicipality> $union */
        $union = [];

        foreach ($locations as $location) {
            $lat = $location->lat === null ? null : (float) $location->lat;
            $lng = $location->lng === null ? null : (float) $location->lng;
            $radius = $radiusOverride !== null
                ? (float) $radiusOverride
                : ($location->coverage_radius === null ? 0.0 : (float) $location->coverage_radius);

            if ($lat === null || $lng === null || $radius <= 0.0) {
                continue; // unconfigured base — needs a geocoded point + radius
            }

            $found = [];
            foreach ($this->gazetteer->near($lat, $lng, $radius) as $m) {
                if ($m->lat === null || $m->lng === null) {
                    continue;
                }

                $distance = Distance::miles($lat, $lng, $m->lat, $m->lng);
                if ($distance > $radius) {
                    continue; // centroid outside the radius
                }

                $found[] = CoverageMunicipality::fromMunicipality($m, $distance, $location->id);

                $union[$m->geoId] = isset($union[$m->geoId])
                    ? $union[$m->geoId]->mergedWith($location->id, $distance)
                    : CoverageMunicipality::fromMunicipality($m, $distance, $location->id);
            }

            usort($found, fn (CoverageMunicipality $a, CoverageMunicipality $b) => $a->distanceMiles <=> $b->distanceMiles);
            $perBase[] = new BaseCoverage($location->id, $location->name, $radius, $found);
        }

        $unionList = array_values($union);
        usort($unionList, fn (CoverageMunicipality $a, CoverageMunicipality $b) => strcmp($a->name, $b->name));

        return new CoverageResult($perBase, $unionList);
    }
}
