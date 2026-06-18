<?php

namespace App\Integrations\Census;

/**
 * Capability role: Census geography lookups for the Locations layer. Coverage is
 * county-based: resolve a point's home county, list a state's counties, and enumerate a
 * county's subdivisions (MCDs). `byName` backs the owner's directed "add a town".
 */
interface MunicipalityGazetteer
{
    /**
     * @return list<Municipality>
     */
    public function near(float $lat, float $lng, float $radiusMiles): array;

    /** The county a point falls in (TIGERweb layer 82 point query), or null. */
    public function countyAt(float $lat, float $lng): ?County;

    /**
     * Every county in a state (for the per-location county multi-select).
     *
     * @return list<County>
     */
    public function countiesInState(string $stateFips): array;

    /**
     * Every county subdivision (municipality) in a county — the coverage unit.
     *
     * @return list<Municipality>
     */
    public function subdivisionsInCounty(string $stateFips, string $countyFips): array;

    /**
     * Boundary polygons for the given county GEOIDs (layer 82, returnGeometry) — for
     * outlining the served counties on the map. Each ring is a list of {lat, lng} vertices
     * (the first ring is the outer boundary; any further rings are holes).
     *
     * @param  list<string>  $geoIds
     * @return list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>
     */
    public function countyPolygons(array $geoIds): array;

    /**
     * Look up municipalities by name (places + MCDs) — for the owner's directed coverage
     * additions ("add a town"). Returns candidates to resolve to a GEOID + point + county.
     *
     * @return list<Municipality>
     */
    public function byName(string $query): array;
}
