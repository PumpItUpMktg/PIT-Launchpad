<?php

namespace App\GeoGrid;

use InvalidArgumentException;

/**
 * Pure geo-grid point geometry — no DB, no API. Given a center coordinate (the Location's GBP point, not a
 * town centroid), an odd grid size, and point spacing in miles, it returns the grid_size² scan points.
 *
 * The one thing that matters here: the LONGITUDE step is derived from the center latitude, never hardcoded.
 * A latitude degree is ~69 miles everywhere, but a longitude degree shrinks with latitude (~53 mi at NJ vs
 * ~69 at the equator). Hardcoding a single step yields a visibly rectangular grid and skewed edge results,
 * which would break any comparison against a Local Falcon scan.
 */
final class GeoGridGeometry
{
    /** Miles per degree of latitude (constant everywhere). */
    private const MILES_PER_LAT_DEGREE = 69.0;

    /**
     * The grid_size² points around the center, row/col 0-indexed from the top-left, offset symmetrically
     * about the center cell. Point (center, center) is exactly the center coordinate.
     *
     * @return list<array{row: int, col: int, lat: float, lng: float}>
     */
    public function points(float $centerLat, float $centerLng, int $gridSize, float $spacingMiles): array
    {
        if ($gridSize < 1 || $gridSize % 2 === 0) {
            throw new InvalidArgumentException("Grid size must be a positive odd number, got {$gridSize}.");
        }
        if ($spacingMiles <= 0) {
            throw new InvalidArgumentException("Spacing must be positive, got {$spacingMiles}.");
        }

        $half = intdiv($gridSize, 2);
        $latStep = $spacingMiles / self::MILES_PER_LAT_DEGREE;
        // Longitude miles-per-degree contracts by cos(latitude) — so cover the same miles with a larger step.
        $lngStep = $spacingMiles / (self::MILES_PER_LAT_DEGREE * cos(deg2rad($centerLat)));

        $points = [];
        for ($row = 0; $row < $gridSize; $row++) {
            for ($col = 0; $col < $gridSize; $col++) {
                $points[] = [
                    'row' => $row,
                    'col' => $col,
                    'lat' => $centerLat + ($row - $half) * $latStep,
                    'lng' => $centerLng + ($col - $half) * $lngStep,
                ];
            }
        }

        return $points;
    }

    /** Points (and DataForSEO requests) per single-keyword scan at this grid size. */
    public function pointCount(int $gridSize): int
    {
        return $gridSize * $gridSize;
    }

    /**
     * Pin spacing (miles) that puts the outermost axis pins at the given RADIUS on an N×N grid — the
     * conversion from Local Falcon's grid "radius" (center → edge, horizontally/vertically) to our
     * pin-to-pin spacing. For a 7×7 the edge is 3 steps out, so a 10-mile radius is 10 ÷ 3 ≈ 3.33 mi
     * spacing. This is the single guard against the radius-vs-spacing footgun that otherwise makes a
     * DataForSEO grid 3× the footprint of the Local Falcon grid it's meant to match.
     */
    public static function spacingForRadius(float $radiusMiles, int $gridSize): float
    {
        return $radiusMiles / max(1, intdiv($gridSize, 2));
    }

    /** The inverse: the center → edge radius (miles) an N×N grid at the given pin spacing covers. */
    public static function radiusForSpacing(float $spacingMiles, int $gridSize): float
    {
        return $spacingMiles * max(1, intdiv($gridSize, 2));
    }
}
