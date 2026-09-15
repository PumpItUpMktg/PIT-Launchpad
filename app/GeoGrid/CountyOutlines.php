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
        $geoIds = array_values(array_unique(array_filter(array_map(fn ($g): string => trim((string) $g), $geoIds), fn (string $g): bool => $g !== '')));
        if ($geoIds === []) {
            return [];
        }

        $out = [];
        $missing = [];
        foreach ($geoIds as $geoId) {
            $cached = Cache::get(self::key($geoId));
            if (is_array($cached)) {
                $out[$geoId] = $cached;
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
                $out[$geoId] = $poly['rings'];
                Cache::put(self::key($geoId), $poly['rings'], now()->addDays(self::CACHE_DAYS));
            }
        }

        return $out;
    }

    private static function key(string $geoId): string
    {
        return 'lp.county_outline.'.$geoId;
    }
}
