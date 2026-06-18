<?php

namespace App\Locations;

use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * County-based coverage (replaces the radius engine): each base location carries a set of
 * selected county GEOIDs; coverage is every county subdivision (municipality) in those
 * counties, joined to ACS population for the Large/Medium/Small grouping, unioned +
 * GEOID-deduped across counties and locations. Owner-directed manual towns
 * ({@see ManualCoverage}) merge in and stay flagged. The county GEOID is 5 digits =
 * STATE(2)+COUNTY(3).
 */
final class CountyCoverage
{
    public function __construct(
        private readonly MunicipalityGazetteer $gazetteer,
        private readonly CensusPopulation $population,
    ) {}

    public function coverage(Site $site): CoverageResult
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

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
            $counties = is_array($location->county_geoids) ? $location->county_geoids : [];

            /** @var array<string, CoverageMunicipality> $found  keyed by geoId (dedupe within a base across counties) */
            $found = [];

            foreach ($counties as $countyGeoId) {
                $countyGeoId = (string) $countyGeoId;
                if (strlen($countyGeoId) < 5) {
                    continue;
                }
                $stateFips = substr($countyGeoId, 0, 2);
                $countyFips = substr($countyGeoId, 2, 3);
                $pop = $popCache["{$stateFips}:{$countyFips}"] ??= $this->population->forCounty($stateFips, $countyFips);

                foreach ($this->gazetteer->subdivisionsInCounty($stateFips, $countyFips) as $m) {
                    $municipality = CoverageMunicipality::fromMunicipality($m, 0.0, $location->id)
                        ->withPopulation($pop[$m->geoId] ?? null);
                    $found[$m->geoId] = $municipality;
                    $union[$m->geoId] = isset($union[$m->geoId])
                        ? $union[$m->geoId]->mergedWith($location->id, 0.0)
                        : $municipality;
                }
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
                $found[$row->geo_id] = $manual;
                $union[$row->geo_id] = isset($union[$row->geo_id])
                    ? $union[$row->geo_id]->mergedWith($location->id, 0.0, true)
                    : $manual;
            }

            if ($found !== []) {
                $perBase[] = new BaseCoverage($location->id, $location->name, 0, array_values($found));
            }
        }

        $unionList = array_values($union);
        usort($unionList, fn (CoverageMunicipality $a, CoverageMunicipality $b) => strcmp($a->name, $b->name));

        return new CoverageResult($perBase, $unionList);
    }
}
