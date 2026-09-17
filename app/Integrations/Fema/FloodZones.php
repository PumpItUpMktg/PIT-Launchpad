<?php

namespace App\Integrations\Fema;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * FEMA's National Flood Hazard Layer, queried for one town's own boundary.
 *
 * The public NFHL ArcGIS service (`hazards.fema.gov/arcgis/rest/services/public/NFHL`) needs no key and no
 * account. Layer 28 is Flood Hazard Zones; the query is grouped server-side, so one POST returns the zone
 * composition rather than hundreds of polygons — verified live against Warrington township PA: an 18 KB
 * request, 0.6s, back with `AE` (SFHA), `A` (SFHA) and `X`.
 *
 * The town's REAL boundary is sent, not a bounding box: a rectangle around a township includes its
 * neighbours' floodplains, and a page that names a flood zone the town does not contain is worse than one
 * that says nothing. The request is a POST because a township ring runs to hundreds of vertices.
 *
 * Any failure returns an empty list — the caller records "not mapped" and the page says nothing.
 */
class FloodZones
{
    private const LAYER = 28;

    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl = 'https://hazards.fema.gov/arcgis/rest/services/public/NFHL/MapServer',
        private readonly int $timeout = 45,
    ) {}

    /**
     * The flood zones intersecting a town's boundary rings (lng/lat, outer ring first).
     *
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     * @return list<array{zone: string, sfha: bool, polygons: int}>
     */
    public function forRings(array $rings): array
    {
        $esriRings = [];
        foreach ($rings as $ring) {
            $points = [];
            foreach ($ring as $point) {
                $points[] = [(float) $point['lng'], (float) $point['lat']];
            }
            if (count($points) >= 4) {   // a ring needs at least a closed triangle
                $esriRings[] = $points;
            }
        }
        if ($esriRings === []) {
            return [];
        }

        try {
            $response = $this->http->timeout($this->timeout)->asForm()->post(
                rtrim($this->baseUrl, '/').'/'.self::LAYER.'/query',
                [
                    'geometry' => json_encode(['rings' => $esriRings, 'spatialReference' => ['wkid' => 4326]]),
                    'geometryType' => 'esriGeometryPolygon',
                    'inSR' => '4326',
                    'spatialRel' => 'esriSpatialRelIntersects',
                    'returnGeometry' => 'false',
                    'groupByFieldsForStatistics' => 'FLD_ZONE,SFHA_TF',
                    'outStatistics' => json_encode([[
                        'statisticType' => 'count',
                        'onStatisticField' => 'OBJECTID',
                        'outStatisticFieldName' => 'n',
                    ]]),
                    'f' => 'json',
                ],
            );
        } catch (Throwable) {
            return [];
        }

        $features = $response->json('features');
        if (! is_array($features)) {
            return [];   // an ArcGIS {"error": …} body, or no answer at all
        }

        $out = [];
        foreach ($features as $feature) {
            $attributes = is_array($feature) ? ($feature['attributes'] ?? null) : null;
            if (! is_array($attributes)) {
                continue;
            }
            $zone = trim((string) ($attributes['FLD_ZONE'] ?? ''));
            if ($zone === '') {
                continue;
            }
            $out[] = [
                'zone' => $zone,
                // FEMA's own flag for "this is the regulatory floodplain", not our reading of the code.
                'sfha' => strtoupper(trim((string) ($attributes['SFHA_TF'] ?? ''))) === 'T',
                'polygons' => (int) ($attributes['n'] ?? 0),
            ];
        }

        return $out;
    }
}
