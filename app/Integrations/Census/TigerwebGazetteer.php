<?php

namespace App\Integrations\Census;

use App\Enums\MunicipalityType;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Real municipality enumeration via the Census TIGERweb ArcGIS REST service. Runs a
 * point-buffer spatial query against the Incorporated Places layer AND the County
 * Subdivisions layer (the MCD layer — essential for NJ/PA), unions the two, and
 * normalizes each feature's centroid (CENTLAT/CENTLON) into a {@see Municipality}. The
 * buffer crosses state lines; no state pre-filter.
 *
 * Layer ids are resolved **by name** from the live MapServer definition (the ids drift
 * by TIGERweb vintage — e.g. tigerWMS_Current = 28/22, ACS2021 = 24/18 — so hardcoding
 * silently breaks on a bump). The configured ids are used only as a fallback if the
 * name lookup fails. Tests Http::fake this.
 */
final class TigerwebGazetteer implements MunicipalityGazetteer
{
    private const PLACES_LAYER_NAME = 'incorporated places';

    private const COUSUB_LAYER_NAME = 'county subdivisions';

    /** @var array{place: int, cousub: int}|null */
    private ?array $resolvedLayers = null;

    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly int $placesLayerFallback,
        private readonly int $cousubLayerFallback,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @return list<Municipality>
     */
    public function near(float $lat, float $lng, float $radiusMiles): array
    {
        $layers = $this->layers();

        return [
            ...$this->query($layers['place'], $lat, $lng, $radiusMiles, MunicipalityType::Place),
            ...$this->query($layers['cousub'], $lat, $lng, $radiusMiles, MunicipalityType::CountySubdivision),
        ];
    }

    /**
     * Resolve the place + county-subdivision layer ids by name from the MapServer
     * definition (memoized), falling back to the configured ids if the lookup fails.
     *
     * @return array{place: int, cousub: int}
     */
    private function layers(): array
    {
        if ($this->resolvedLayers !== null) {
            return $this->resolvedLayers;
        }

        $byName = $this->layerNameMap();

        return $this->resolvedLayers = [
            'place' => $byName[self::PLACES_LAYER_NAME] ?? $this->placesLayerFallback,
            'cousub' => $byName[self::COUSUB_LAYER_NAME] ?? $this->cousubLayerFallback,
        ];
    }

    /**
     * @return array<string, int> lowercased layer name → id
     */
    private function layerNameMap(): array
    {
        try {
            $response = $this->http->timeout($this->timeout)->get(rtrim($this->baseUrl, '/'), ['f' => 'json']);
            $layers = is_array($response->json('layers')) ? $response->json('layers') : [];
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($layers as $layer) {
            if (is_array($layer) && isset($layer['id'], $layer['name'])) {
                $map[strtolower(trim((string) $layer['name']))] = (int) $layer['id'];
            }
        }

        return $map;
    }

    /**
     * @return list<Municipality>
     */
    private function query(int $layer, float $lat, float $lng, float $radiusMiles, MunicipalityType $type): array
    {
        // TIGERweb's MapServer layers don't support a distance/units query buffer
        // (supportsQueryWithDistance = false) — that combo returns HTTP 200 + a 400
        // "Failed to execute query" (and `geodesic` is a geometry-service param, not a
        // query one). Query an ENVELOPE (bounding box) around the point instead; the
        // caller ({@see LocationCoverage}) then Haversine-clips the box to the true radius
        // circle (the box over-returns in the corners). inSR=4326 = the bbox is lat/lng
        // (the layer data is Web Mercator).
        $dLat = $radiusMiles / 69.0;
        $dLon = $radiusMiles / (69.0 * max(cos(deg2rad($lat)), 0.01));

        return $this->mapFeatures($this->fetch($layer, [
            'f' => 'json',
            'where' => '1=1',
            'geometry' => (string) json_encode([
                'xmin' => $lng - $dLon,
                'ymin' => $lat - $dLat,
                'xmax' => $lng + $dLon,
                'ymax' => $lat + $dLat,
                'spatialReference' => ['wkid' => 4326],
            ]),
            'geometryType' => 'esriGeometryEnvelope',
            'inSR' => 4326,
            'spatialRel' => 'esriSpatialRelIntersects',
            'outFields' => 'GEOID,NAME,BASENAME,STUSAB,STATE,CENTLAT,CENTLON',
            'returnGeometry' => 'false',
        ]), $type);
    }

    /**
     * Issue a layer query and LOG the outcome — the raw URL + feature count, with a loud
     * warning on zero features (the SR/vintage class of bug must never be a silent 0).
     *
     * @param  array<string, mixed>  $params
     * @return mixed
     */
    private function fetch(int $layer, array $params)
    {
        $url = rtrim($this->baseUrl, '/')."/{$layer}/query";
        $response = $this->http->timeout($this->timeout)->get($url, $params);
        $features = is_array($response->json('features')) ? $response->json('features') : [];
        $fullUrl = $url.'?'.http_build_query($params);

        // TEMP: surface the raw request + outcome to the Locations page (diagnose coverage-0).
        app(TigerwebDebug::class)->record([
            'layer' => $layer,
            'url' => $fullUrl,
            'status' => $response->status(),
            'count' => count($features),
            'error' => $response->json('error'),
        ]);

        if ($features === []) {
            Log::warning('TIGERweb returned 0 features', [
                'url' => $fullUrl,
                'status' => $response->status(),
                'error' => $response->json('error'),
                'body' => Str::limit((string) $response->body(), 400),
            ]);
        } else {
            Log::debug('TIGERweb query ok', ['url' => $fullUrl, 'features' => count($features)]);
        }

        return $response->json('features');
    }

    /**
     * @return list<Municipality>
     */
    public function byName(string $query): array
    {
        // Operators type "Sparta, NJ" / "Doylestown, PA". BASENAME is just "Sparta", so a
        // raw LIKE on the whole string never matches — split off a trailing 2-letter state,
        // search the name, and filter results by that state.
        $parts = array_map('trim', explode(',', trim($query)));
        $name = $parts[0];
        $state = (count($parts) > 1 && preg_match('/^[A-Za-z]{2}$/', (string) end($parts)))
            ? strtoupper((string) end($parts))
            : null;

        if ($name === '') {
            return [];
        }

        $layers = $this->layers();
        $results = [
            ...$this->queryByName($layers['place'], $name, MunicipalityType::Place),
            ...$this->queryByName($layers['cousub'], $name, MunicipalityType::CountySubdivision),
        ];

        return $state === null
            ? $results
            : array_values(array_filter($results, fn (Municipality $m) => $m->state === $state));
    }

    /**
     * @return list<Municipality>
     */
    private function queryByName(int $layer, string $query, MunicipalityType $type): array
    {
        $escaped = str_replace("'", "''", strtoupper($query));

        return $this->mapFeatures($this->fetch($layer, [
            'f' => 'json',
            'where' => "UPPER(BASENAME) LIKE '%{$escaped}%'",
            'outFields' => 'GEOID,NAME,BASENAME,STUSAB,STATE,CENTLAT,CENTLON',
            'returnGeometry' => 'false',
            'orderByFields' => 'BASENAME',
            'resultRecordCount' => 25,
        ]), $type);
    }

    /**
     * @param  mixed  $features
     * @return list<Municipality>
     */
    private function mapFeatures($features, MunicipalityType $type): array
    {
        $features = is_array($features) ? $features : [];

        $out = [];
        foreach ($features as $feature) {
            $a = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : null;
            if ($a === null || ($geoId = trim((string) ($a['GEOID'] ?? ''))) === '') {
                continue;
            }

            $out[] = new Municipality(
                geoId: $geoId,
                name: trim((string) ($a['BASENAME'] ?? $a['NAME'] ?? '')),
                type: $type,
                state: ($s = trim((string) ($a['STUSAB'] ?? ''))) !== '' ? $s : null,
                lat: isset($a['CENTLAT']) ? (float) $a['CENTLAT'] : null,
                lng: isset($a['CENTLON']) ? (float) $a['CENTLON'] : null,
            );
        }

        return $out;
    }
}
