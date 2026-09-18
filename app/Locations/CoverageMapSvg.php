<?php

namespace App\Locations;

use App\GeoGrid\MapProjection;
use Launchpad\Companion\Render\AreaMap;

/**
 * The operator coverage map's geometry, projected for inline SVG.
 *
 * The map was Leaflet over CARTO's basemap tiles, which now demand an API key and stamp
 * "API KEY REQUIRED" across the picture. The customer-facing map stopped using tiles for that reason
 * ({@see AreaMap}); this is the same move for the operator's own view, over
 * {@see MapProjection} — the projector every other town map in the admin already draws with, so a county
 * lands in the same place here as on the Town Rank and Service Areas boards.
 *
 * Pure: shapes in, coordinates out. The Blade renders the elements (and does the escaping); Livewire
 * re-renders the whole figure when coverage changes, so there is no JS to keep the picture in sync.
 */
final class CoverageMapSvg
{
    /**
     * @param  list<array{geo_id?: string, name?: string, rings?: list<list<array{lat: float, lng: float}>>}>  $polygons
     * @param  list<array{name?: string, lat?: float|null, lng?: float|null, color?: string}>  $pins
     * @param  list<array{name?: string, lat?: float|null, lng?: float|null}>  $manual
     * @return array{counties: list<array{name: string, paths: list<string>}>, pins: list<array{name: string, x: float, y: float, color: string}>, flags: list<array{name: string, x: float, y: float}>}|null
     */
    public static function build(array $polygons, array $pins, array $manual): ?array
    {
        // Both extent helpers read a KEYED map (county id → rings, key → point), so the groupings are
        // kept rather than flattened into one bag of coordinates.
        $ringGroups = [];
        foreach ($polygons as $i => $county) {
            $rings = array_values(array_filter((array) ($county['rings'] ?? []), fn ($ring): bool => $ring !== []));
            if ($rings !== []) {
                $ringGroups[trim((string) ($county['geo_id'] ?? '')) ?: 'county-'.$i] = $rings;
            }
        }

        $coords = [];
        foreach ([...$pins, ...$manual] as $i => $point) {
            if (isset($point['lat'], $point['lng'])) {
                $coords['point-'.$i] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }

        if ($ringGroups === [] && count($coords) < 2) {
            return null;   // nothing to draw, or a single dot on a blank field
        }

        // One frame over everything, so a base pin sits inside the county it serves.
        $project = MapProjection::projector(MapProjection::unionExtent(array_values(array_filter([
            $ringGroups !== [] ? MapProjection::ringsExtent($ringGroups) : null,
            $coords !== [] ? MapProjection::pointsExtent($coords) : null,
        ]))));

        $counties = [];
        foreach ($polygons as $county) {
            $paths = MapProjection::paths(
                array_values(array_filter((array) ($county['rings'] ?? []), fn ($ring): bool => $ring !== [])),
                $project,
            );
            if ($paths !== []) {
                $counties[] = ['name' => trim((string) ($county['name'] ?? '')), 'paths' => $paths];
            }
        }

        $plot = function (array $point) use ($project): ?array {
            if (! isset($point['lat'], $point['lng'])) {
                return null;
            }
            [$x, $y] = $project((float) $point['lat'], (float) $point['lng']);

            return ['name' => trim((string) ($point['name'] ?? '')), 'x' => $x, 'y' => $y];
        };

        $plotted = [];
        foreach ($pins as $pin) {
            $point = $plot($pin);
            if ($point !== null) {
                $plotted[] = $point + ['color' => trim((string) ($pin['color'] ?? '')) ?: '#2563eb'];
            }
        }

        $flags = [];
        foreach ($manual as $marker) {
            $point = $plot($marker);
            if ($point !== null) {
                $flags[] = $point;
            }
        }

        return ['counties' => $counties, 'pins' => $plotted, 'flags' => $flags];
    }
}
