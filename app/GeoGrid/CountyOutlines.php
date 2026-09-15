<?php

namespace App\GeoGrid;

use App\Integrations\Census\MunicipalityGazetteer;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * County boundary rings for drawing under a map (§ Town Rank service areas): the same Census TIGERweb
 * polygons the published service-area map uses ({@see MunicipalityGazetteer::countyPolygons()}), read
 * through the cache so a county is fetched once a month, not once a page view. Best-effort: a gazetteer
 * failure yields no outlines and the map simply draws its dots on a blank ground.
 */
final class CountyOutlines
{
    private const CACHE_DAYS = 30;

    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * Rings per 5-digit county GEOID (lng/lat points, outer ring first), for the GEOIDs the gazetteer knows.
     *
     * @param  list<string>  $geoIds
     * @return array<string, list<list<array{lat: float, lng: float}>>>
     */
    public function for(array $geoIds): array
    {
        return array_map(fn (array $c): array => $c['rings'], $this->outlines($geoIds));
    }

    /**
     * The Census name per county GEOID ("Morris County") for the GEOIDs the gazetteer knows — the label
     * fallback when the county registry has no row for a served county.
     *
     * @param  list<string>  $geoIds
     * @return array<string, string>
     */
    public function names(array $geoIds): array
    {
        return array_map(fn (array $c): string => $c['name'], $this->outlines($geoIds));
    }

    /**
     * @param  list<string>  $geoIds
     * @return array<string, array{name: string, rings: list<list<array{lat: float, lng: float}>>}>
     */
    private function outlines(array $geoIds): array
    {
        $geoIds = array_values(array_unique(array_filter(array_map(fn ($g): string => trim((string) $g), $geoIds), fn (string $g): bool => $g !== '')));
        if ($geoIds === []) {
            return [];
        }

        $out = [];
        $missing = [];
        foreach ($geoIds as $geoId) {
            $cached = Cache::get(self::key($geoId));
            if (is_array($cached) && isset($cached['name'], $cached['rings']) && is_array($cached['rings'])) {
                $out[$geoId] = ['name' => (string) $cached['name'], 'rings' => $cached['rings']];
            } else {
                $missing[] = $geoId;
            }
        }

        if ($missing !== []) {
            try {
                $polys = $this->gazetteer->countyPolygons($missing);
            } catch (Throwable) {
                $polys = [];
            }
            foreach ($polys as $poly) {
                $geoId = $poly['geo_id'];
                if ($geoId === '' || $poly['rings'] === []) {
                    continue;
                }
                $out[$geoId] = ['name' => $poly['name'], 'rings' => $poly['rings']];
                Cache::put(self::key($geoId), $out[$geoId], now()->addDays(self::CACHE_DAYS));
            }
        }

        return $out;
    }

    private static function key(string $geoId): string
    {
        return 'lp.county_outline.'.$geoId;
    }
}
