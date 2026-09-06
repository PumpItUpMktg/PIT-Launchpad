<?php

namespace App\Support;

use App\Onboarding\IntakeCollector;

/**
 * The plausible US service-area bounding box (CONUS + AK + HI + PR), config-tunable. A market coordinate
 * outside it is corruption, not a real market — in production a South-Pacific pair (-29.6, -175.4) once
 * centred an entire local grid over open ocean, so every maps cell returned 40102 "No Search Results".
 * A valid Earth coordinate is not enough; it must fall in the service area.
 *
 * Pure + side-effect-free so both the intake guard ({@see IntakeCollector::saveMarkets})
 * and the geo report/repair command can share one definition.
 */
final class GeoBounds
{
    /**
     * @return array{lat_min: float, lat_max: float, lng_min: float, lng_max: float}
     */
    public static function serviceArea(): array
    {
        $b = config('launchpad.geo.service_area_bounds');
        $b = is_array($b) ? $b : [];

        return [
            'lat_min' => (float) ($b['lat_min'] ?? 17.0),  // PR ~17.9
            'lat_max' => (float) ($b['lat_max'] ?? 72.0),  // AK ~71.4
            'lng_min' => (float) ($b['lng_min'] ?? -180.0),
            'lng_max' => (float) ($b['lng_max'] ?? -64.0),  // PR ~-65.2
        ];
    }

    /** Whether a coordinate plausibly falls in the US service area. Null (either component) is never valid. */
    public static function isWithinServiceArea(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        $b = self::serviceArea();

        return $lat >= $b['lat_min'] && $lat <= $b['lat_max']
            && $lng >= $b['lng_min'] && $lng <= $b['lng_max'];
    }
}
