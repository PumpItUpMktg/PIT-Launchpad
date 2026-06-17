<?php

namespace App\Integrations\Census;

use App\Enums\MunicipalityType;
use Illuminate\Http\Client\Factory as Http;

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
        $response = $this->http
            ->timeout($this->timeout)
            ->get(rtrim($this->baseUrl, '/')."/{$layer}/query", [
                'f' => 'json',
                'where' => '1=1',
                'geometry' => (string) json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]]),
                'geometryType' => 'esriGeometryPoint',
                'inSR' => 4326,
                'outSR' => 4326,
                'distance' => $radiusMiles,
                'units' => 'esriSRUnit_StatuteMile',
                'spatialRel' => 'esriSpatialRelIntersects',
                'outFields' => 'GEOID,NAME,BASENAME,STUSAB,STATE,CENTLAT,CENTLON',
                'returnGeometry' => 'false',
            ]);

        return $this->mapFeatures($response->json('features'), $type);
    }

    /**
     * @return list<Municipality>
     */
    public function byName(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $layers = $this->layers();

        return [
            ...$this->queryByName($layers['place'], $query, MunicipalityType::Place),
            ...$this->queryByName($layers['cousub'], $query, MunicipalityType::CountySubdivision),
        ];
    }

    /**
     * @return list<Municipality>
     */
    private function queryByName(int $layer, string $query, MunicipalityType $type): array
    {
        $escaped = str_replace("'", "''", strtoupper($query));

        $response = $this->http
            ->timeout($this->timeout)
            ->get(rtrim($this->baseUrl, '/')."/{$layer}/query", [
                'f' => 'json',
                'where' => "UPPER(BASENAME) LIKE '%{$escaped}%'",
                'outFields' => 'GEOID,NAME,BASENAME,STUSAB,STATE,CENTLAT,CENTLON',
                'returnGeometry' => 'false',
                'orderByFields' => 'BASENAME',
                'resultRecordCount' => 25,
            ]);

        return $this->mapFeatures($response->json('features'), $type);
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
