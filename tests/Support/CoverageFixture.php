<?php

namespace Tests\Support;

use App\Enums\MunicipalityType;
use App\Integrations\Census\Municipality;

/**
 * Deterministic municipality candidates + base coordinates for the Locations coverage
 * engine tests. Coordinates are chosen so the 25mi Haversine filter cleanly includes /
 * excludes and the two bases share exactly one municipality (union dedupe).
 */
final class CoverageFixture
{
    // Base A (NJ) and Base B (near the PA border).
    public const A_LAT = 40.70;

    public const A_LNG = -74.50;

    public const B_LAT = 40.50;

    public const B_LNG = -75.30;

    public const RADIUS = 25;

    /**
     * @return list<Municipality>
     */
    public static function municipalities(): array
    {
        return [
            // in range of A only
            new Municipality('3445000', 'Maplewood', MunicipalityType::Place, 'NJ', 40.73, -74.47),
            new Municipality('3441310', 'Livingston Twp', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.32),
            // in range of B only — cross-border PA place
            new Municipality('4222648', 'Easton', MunicipalityType::Place, 'PA', 40.69, -75.22),
            // in range of BOTH (union dedupe), an MCD
            new Municipality('3401990', 'Clinton Twp', MunicipalityType::CountySubdivision, 'NJ', 40.61, -74.90),
            // out of range of both → dropped by the distance filter
            new Municipality('4252848', 'Scranton', MunicipalityType::Place, 'PA', 41.41, -75.66),
            // no centroid → skipped
            new Municipality('9999999', 'No Centroid', MunicipalityType::Place, 'NJ', null, null),
        ];
    }
}
