<?php

namespace App\Integrations\Usda;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * USDA soil drainage under a town, AREA-WEIGHTED, from Soil Data Access (SSURGO).
 *
 * Keyless and free. The query intersects the soil map-unit polygons with the town's own boundary and
 * sums the shared area per drainage class, so the answer is the share of the town's mapped GROUND that
 * drains each way — not a count of map units, which would weight a postage-stamp polygon the same as
 * half a township.
 *
 * Verified live before this was written, against Warrington township's real 371-vertex boundary: 0.9s,
 * and 66% of its ground is somewhat poorly or poorly drained. That is the clay-versus-sand variable a
 * homeowner with a wet basement is living with, in USDA's own classification.
 *
 * Only EXTERIOR rings are sent. An ArcGIS ring set marks holes by winding order, and sending a hole as
 * land would quietly count ground the town does not contain; a hole omitted is at worst a small
 * over-count of the town's own area, which does not move a share.
 *
 * Any failure returns an empty list — the caller records "not surveyed" and the page says nothing.
 */
class SoilDrainage
{
    private const URL = 'https://sdmdataaccess.sc.egov.usda.gov/Tabular/post.rest';

    public function __construct(
        private readonly Http $http,
        private readonly int $timeout = 90,
    ) {}

    /**
     * The one class in the survey that is not land: soil under a bay or an estuary. A coastal town's
     * boundary takes in open water, and counting seabed as ground is how Brooklyn came back "drains
     * freely" — most of what was measured was harbour.
     */
    public const WATER = 'Subaqueous';

    /**
     * Drainage classes under these boundary rings, with each class's share of the mapped LAND (0–1),
     * largest first, plus the share of the mapped area that is underwater.
     *
     * Rows the survey has no class for are excluded from both — they prove the town was surveyed and
     * say nothing about its drainage.
     *
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     * @return array{classes: list<array{class: string, share: float}>, water_share: float}
     */
    public function forRings(array $rings): array
    {
        $empty = ['classes' => [], 'water_share' => 0.0];
        $wkt = $this->wkt($rings);
        if ($wkt === null) {
            return $empty;
        }

        $sql = sprintf(
            'SELECT m.drclassdcd AS drainage, '
            ."SUM(p.mupolygongeo.STIntersection(geometry::STGeomFromText('%s', 4326)).STArea()) AS shared "
            .'FROM mupolygon p JOIN muaggatt m ON m.mukey = p.mukey '
            ."WHERE p.mupolygongeo.STIntersects(geometry::STGeomFromText('%s', 4326)) = 1 "
            .'GROUP BY m.drclassdcd',
            $wkt,
            $wkt,
        );

        try {
            $response = $this->http->timeout($this->timeout)->asJson()->post(self::URL, [
                'format' => 'JSON',
                'query' => $sql,
            ]);
        } catch (Throwable) {
            return $empty;
        }

        $rows = $response->successful() ? $response->json('Table') : null;
        if (! is_array($rows)) {
            return $empty;
        }

        $areas = [];
        $land = 0.0;
        $water = 0.0;
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) < 2) {
                continue;
            }
            $class = trim((string) ($row[0] ?? ''));
            $area = (float) ($row[1] ?? 0);
            if ($class === '' || $area <= 0) {
                continue;   // ground the survey gives no class — not a drainage answer either way
            }
            if ($class === self::WATER) {
                $water += $area;

                continue;   // seabed is not drainage; it is not ground
            }
            $areas[$class] = ($areas[$class] ?? 0.0) + $area;
            $land += $area;
        }
        if ($land <= 0.0) {
            // All water, or nothing classed: surveyed, but with no ground to describe.
            return ['classes' => [], 'water_share' => $water > 0.0 ? 1.0 : 0.0];
        }

        arsort($areas);
        $out = [];
        foreach ($areas as $class => $area) {
            $out[] = ['class' => $class, 'share' => round($area / $land, 4)];
        }

        return ['classes' => $out, 'water_share' => round($water / ($land + $water), 4)];
    }

    /**
     * A MULTIPOLYGON of the exterior rings. Interior rings (holes) wind the other way and are dropped.
     *
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     */
    private function wkt(array $rings): ?string
    {
        $polygons = [];
        foreach ($rings as $ring) {
            if (count($ring) < 4 || $this->signedArea($ring) <= 0) {
                continue;   // too small to close, or a hole
            }
            $points = [];
            foreach ($ring as $point) {
                $points[] = sprintf('%.6f %.6f', (float) $point['lng'], (float) $point['lat']);
            }
            // WKT wants the ring closed; ArcGIS usually closes it already.
            if ($points[0] !== $points[count($points) - 1]) {
                $points[] = $points[0];
            }
            $polygons[] = '(('.implode(', ', $points).'))';
        }

        return $polygons === [] ? null : 'multipolygon('.implode(', ', $polygons).')';
    }

    /**
     * Shoelace: positive for a clockwise ArcGIS exterior ring, negative for a hole.
     *
     * @param  list<array{lat: float, lng: float}>  $ring
     */
    private function signedArea(array $ring): float
    {
        $sum = 0.0;
        $count = count($ring);
        for ($i = 0; $i < $count; $i++) {
            $a = $ring[$i];
            $b = $ring[($i + 1) % $count];
            $sum += ((float) $a['lng'] * (float) $b['lat']) - ((float) $b['lng'] * (float) $a['lat']);
        }

        // ArcGIS exterior rings are clockwise, which is NEGATIVE under this formula's convention.
        return -$sum;
    }
}
