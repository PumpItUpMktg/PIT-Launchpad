<?php

namespace App\Locations;

/**
 * Great-circle distance in statute miles (Haversine). Used to confirm a municipality
 * centroid falls within a base location's radius — centroid-in-range is the pragmatic
 * coverage rule.
 */
final class Distance
{
    private const EARTH_RADIUS_MILES = 3958.7613;

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_MILES * asin(min(1.0, sqrt($a)));
    }
}
