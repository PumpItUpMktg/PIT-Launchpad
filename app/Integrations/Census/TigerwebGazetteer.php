<?php

namespace App\Integrations\Census;

use App\Enums\MunicipalityType;
use Illuminate\Http\Client\Factory as Http;

/**
 * Real municipality enumeration via the Census TIGERweb ArcGIS REST service. Runs a
 * point-buffer spatial query against the Incorporated Places layer AND the County
 * Subdivisions layer (the MCD layer — essential for NJ/PA), unions the two, and
 * normalizes each feature's centroid (CENTLAT/CENTLON) into a {@see Municipality}. The
 * buffer crosses state lines; no state pre-filter. Layer ids are config-driven so a
 * TIGERweb vintage change is a config edit, not a code change. Tests Http::fake this.
 */
final class TigerwebGazetteer implements MunicipalityGazetteer
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly int $placesLayer,
        private readonly int $cousubLayer,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @return list<Municipality>
     */
    public function near(float $lat, float $lng, float $radiusMiles): array
    {
        return [
            ...$this->query($this->placesLayer, $lat, $lng, $radiusMiles, MunicipalityType::Place),
            ...$this->query($this->cousubLayer, $lat, $lng, $radiusMiles, MunicipalityType::CountySubdivision),
        ];
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

        $features = is_array($response->json('features')) ? $response->json('features') : [];

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
