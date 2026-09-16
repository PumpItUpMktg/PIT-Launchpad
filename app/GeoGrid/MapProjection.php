<?php

namespace App\GeoGrid;

/**
 * Lat/lng → SVG geometry, shared by every town map: the frame, the uniform projector, and the path strings.
 *
 * Kept in one place because two maps drawn with different frames cannot be compared — and the whole point
 * of the website map and the GBP map sitting side by side is that a town lands on the same spot in both.
 */
final class MapProjection
{
    /**
     * A lat/lng → SVG (0–100 viewBox) projector for one frame: the frame is centred, scaled uniformly (the
     * longitude span corrected by cos(latitude) so a county keeps its shape) to fit inside 6..94, north up.
     *
     * @param  array{minLat: float, maxLat: float, minLng: float, maxLng: float}  $extent
     * @return callable(float, float): array{float, float}
     */
    public static function projector(array $extent): callable
    {
        $midLat = ($extent['minLat'] + $extent['maxLat']) / 2;
        $midLng = ($extent['minLng'] + $extent['maxLng']) / 2;
        $cos = max(0.05, cos(deg2rad($midLat)));
        $spanX = ($extent['maxLng'] - $extent['minLng']) * $cos;
        $spanY = $extent['maxLat'] - $extent['minLat'];
        $span = max($spanX, $spanY);
        $scale = $span > 0 ? 88 / $span : 0.0;

        return fn (float $lat, float $lng): array => [
            round(50 + ($lng - $midLng) * $cos * $scale, 2),
            round(50 - ($lat - $midLat) * $scale, 2),
        ];
    }

    /**
     * SVG path strings (one per ring, closed) for a polygon under the frame's projector.
     *
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     * @param  callable(float, float): array{float, float}  $project
     * @return list<string>
     */
    public static function paths(array $rings, callable $project): array
    {
        $paths = [];
        foreach ($rings as $ring) {
            $d = '';
            foreach ($ring as $i => $pt) {
                [$x, $y] = $project((float) $pt['lat'], (float) $pt['lng']);
                $d .= ($i === 0 ? 'M' : 'L').$x.' '.$y.' ';
            }
            if ($d !== '') {
                $paths[] = trim($d).' Z';
            }
        }

        return $paths;
    }

    /**
     * The extent covering every non-empty extent given (an empty one is all-zero and skipped).
     *
     * @param  list<array{minLat: float, maxLat: float, minLng: float, maxLng: float}>  $extents
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function unionExtent(array $extents): array
    {
        $live = array_values(array_filter($extents, fn (array $e): bool => $e !== ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0]));
        if ($live === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return [
            'minLat' => min(array_column($live, 'minLat')),
            'maxLat' => max(array_column($live, 'maxLat')),
            'minLng' => min(array_column($live, 'minLng')),
            'maxLng' => max(array_column($live, 'maxLng')),
        ];
    }

    /**
     * @param  array<string, array{lat: float, lng: float}>  $coords
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function pointsExtent(array $coords): array
    {
        $lats = array_column($coords, 'lat');
        $lngs = array_column($coords, 'lng');
        if ($lats === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return ['minLat' => min($lats), 'maxLat' => max($lats), 'minLng' => min($lngs), 'maxLng' => max($lngs)];
    }

    /**
     * @param  array<string, list<list<array{lat: float, lng: float}>>>  $rings
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function ringsExtent(array $rings): array
    {
        $lats = [];
        $lngs = [];
        foreach ($rings as $countyRings) {
            foreach ($countyRings as $ring) {
                foreach ($ring as $pt) {
                    $lats[] = (float) $pt['lat'];
                    $lngs[] = (float) $pt['lng'];
                }
            }
        }
        if ($lats === []) {
            return ['minLat' => 0.0, 'maxLat' => 0.0, 'minLng' => 0.0, 'maxLng' => 0.0];
        }

        return ['minLat' => min($lats), 'maxLat' => max($lats), 'minLng' => min($lngs), 'maxLng' => max($lngs)];
    }
}
