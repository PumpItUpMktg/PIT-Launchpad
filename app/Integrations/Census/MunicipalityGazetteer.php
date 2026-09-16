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
     * The place / county-subdivision a point falls IN — a point-intersect query, MCD layer first
     * (essential for NJ/PA), then Incorporated Places. This is the point→identity lookup Job Capture needs
     * to normalize a captured coordinate to one canonical municipality (GEOID). Null if nothing contains it.
     */
    public function placeAt(float $lat, float $lng): ?Municipality;

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
     * Boundary polygons for towns by GEOID — 10-digit county subdivisions and 7-digit places — for
     * colouring a town's own shape on a map. Same ring shape as {@see countyPolygons()}; a GEOID the
     * source doesn't know is simply absent from the result.
     *
     * @param  list<string>  $geoIds
     * @return list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>
     */
    public function townPolygons(array $geoIds): array;

    /**
     * Look up municipalities by name (places + MCDs) — for the owner's directed coverage
     * additions ("add a town"). Returns candidates to resolve to a GEOID + point + county.
     *
     * @return list<Municipality>
     */
    public function byName(string $query): array;
}
