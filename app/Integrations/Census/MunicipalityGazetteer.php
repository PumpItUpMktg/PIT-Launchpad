<?php

namespace App\Integrations\Census;

/**
 * Capability role: enumerate the municipalities near a geocoded point — BOTH
 * incorporated places AND county subdivisions (MCDs), so NJ/PA townships/boroughs are
 * never missed. The radius is NOT pre-filtered to a single state; the buffer crosses
 * state lines. Returns candidate records near the point; the coverage engine applies
 * the exact Haversine distance filter + the multi-base union.
 */
interface MunicipalityGazetteer
{
    /**
     * @return list<Municipality>
     */
    public function near(float $lat, float $lng, float $radiusMiles): array;

    /**
     * Look up municipalities by name (places + MCDs) — for the owner's directed coverage
     * additions ("add a town"). Returns candidates to resolve to a GEOID + point + county.
     *
     * @return list<Municipality>
     */
    public function byName(string $query): array;
}
