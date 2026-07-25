<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves the GEOMETRY for the "Areas we serve" interactive map: the served counties as boundary
 * polygons (the same {@see MunicipalityGazetteer::countyPolygons()} seam the onboarding Territory map
 * draws) plus the covered towns as tiered points (major → small, from §1 `CoverageArea`, which carries
 * the census size tier + a geocoded lat/lng).
 *
 * This is map DATA only — it rides the meta-blob as `service_area_map` (NOT inside `post_content`,
 * since WordPress kses strips embedded JSON/scripts/data-attributes). The companion plugin stores it
 * and prints it for the theme's Leaflet init to read; the block markup carries only the mount container
 * + the text fallback. Best-effort throughout: a gazetteer failure drops the polygons (the towns still
 * plot), and no geometry at all returns null (the section then renders text-only / data-gates as before).
 */
final class ServiceAreaMap
{
    /** Largest-first ordering of the census size tiers; an ungrouped town sorts last. */
    private const TIER_RANK = ['major' => 0, 'large' => 1, 'medium' => 2, 'small' => 3];

    /** The map can carry more points than the text list, but stays bounded so the payload stays small. */
    private const MAX_CITIES = 60;

    /** County boundaries don't move — cache them so a re-publish isn't another TIGERweb round-trip. */
    private const POLYGON_CACHE_DAYS = 30;

    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * @return array{
     *     counties: list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>,
     *     cities: list<array{name: string, lat: float, lng: float, tier: string}>,
     *     center: array{lat: float, lng: float}|null
     * }|null
     */
    public function for(string $siteId): ?array
    {
        $polygons = $this->polygons($siteId);
        $cities = $this->cities($siteId);

        // No geometry at all → no map; the section falls back to text / data-gating upstream.
        if ($polygons === [] && $cities === []) {
            return null;
        }

        return [
            'counties' => $polygons,
            'cities' => $cities,
            'center' => $this->center($polygons, $cities),
        ];
    }

    /**
     * A LOCATION-scoped map for a location hub: that location's own served county polygons + the towns
     * IT serves (as clickable points — each carries the URL of its published town page), plus a pin for
     * the location itself. Same shape as {@see for()} with a `url` on each city so the theme's Leaflet
     * init can link the markers. Null when there's no geometry at all (the section then stays text-only).
     *
     * @return array{
     *     counties: list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>,
     *     cities: list<array{name: string, lat: float, lng: float, tier: string, url: string}>,
     *     center: array{lat: float, lng: float}|null,
     *     pin?: array{lat: float, lng: float, label: string}
     * }|null
     */
    public function forLocation(Location $location): ?array
    {
        $geoIds = collect(is_array($location->county_geoids) ? $location->county_geoids : [])
            ->map(fn ($g): string => (string) $g)
            ->filter(fn (string $g): bool => strlen($g) >= 5)
            ->unique()->values()->all();

        $polygons = $this->polygonsFor($geoIds);
        $cities = $this->locationCities($location);
        $pin = ($location->lat !== null && $location->lng !== null)
            ? ['lat' => (float) $location->lat, 'lng' => (float) $location->lng, 'label' => $this->locationLabel($location)]
            : null;

        if ($polygons === [] && $cities === [] && $pin === null) {
            return null;
        }

        $out = ['counties' => $polygons, 'cities' => $cities, 'center' => $this->center($polygons, $cities)];
        if ($pin !== null) {
            $out['pin'] = $pin;
            $out['center'] ??= ['lat' => $pin['lat'], 'lng' => $pin['lng']];
        }

        return $out;
    }

    /**
     * The location's served towns as tiered, LINKED map points — only towns that have a live (published)
     * town page under this location AND a geocoded coverage row, matched by name. Largest-first, capped.
     *
     * @return list<array{name: string, lat: float, lng: float, tier: string, url: string}>
     */
    private function locationCities(Location $location): array
    {
        // town name (normalized) => the published town page URL under this location.
        $urls = [];
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->where('parent_location_id', $location->id)
            ->whereNull('location_id')
            ->whereNull('primary_service_id')
            ->whereNotNull('slug')
            ->get(['title', 'slug']);
        foreach ($pages as $page) {
            $key = $this->townKey((string) $page->title);
            if ($key !== '' && ! isset($urls[$key])) {
                $urls[$key] = '/'.ltrim((string) $page->slug, '/');
            }
        }
        if ($urls === []) {
            return [];
        }

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->get(['name', 'lat', 'lng', 'size_tier', 'population']);

        $items = [];
        $seen = [];
        foreach ($areas as $area) {
            $key = $this->townKey((string) $area->name);
            if ($key === '' || ! isset($urls[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tier = (string) ($area->size_tier ?? '');
            $items[] = [
                'point' => [
                    'name' => (string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim((string) $area->name)),
                    'lat' => (float) $area->lat,
                    'lng' => (float) $area->lng,
                    'tier' => $tier !== '' ? $tier : 'small',
                    'url' => $urls[$key],
                ],
                'key' => [self::TIER_RANK[$tier] ?? 4, -1 * (int) ($area->population ?? 0), $key],
            ];
        }

        usort($items, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_map(fn (array $i): array => $i['point'], array_slice($items, 0, self::MAX_CITIES));
    }

    /** "{City}, {ST}" (or the location name) for the pin tooltip. */
    private function locationLabel(Location $location): string
    {
        ['city' => $city, 'state' => $state] = $location->cityState();
        $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
        $state = trim($state);

        return $city !== '' && $state !== '' ? "{$city}, {$state}" : ($city !== '' ? $city : 'Our location');
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower-case). */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }

    /**
     * The served counties as boundary polygons, from the selected `county_geoids` (the same selection
     * the onboarding map outlines). Best-effort: any gazetteer failure yields [].
     *
     * @return list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>
     */
    private function polygons(string $siteId): array
    {
        $geoIds = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->pluck('county_geoids')
            ->flatMap(fn ($v): array => is_array($v) ? $v : [])
            ->map(fn ($g): string => (string) $g)
            ->filter(fn (string $g): bool => strlen($g) >= 5)
            ->unique()
            ->values()
            ->all();

        return $this->polygonsFor($geoIds);
    }

    /**
     * County polygons for a specific set of GEOIDs (cached, best-effort). Shared by the site-wide map
     * and the per-location map.
     *
     * @param  list<string>  $geoIds
     * @return list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>
     */
    private function polygonsFor(array $geoIds): array
    {
        $geoIds = array_values(array_filter(array_map('strval', $geoIds), fn (string $g): bool => strlen($g) >= 5));
        if ($geoIds === []) {
            return [];
        }

        sort($geoIds);
        $key = 'lp.county_polygons.'.md5(implode(',', $geoIds));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $polygons = $this->gazetteer->countyPolygons($geoIds);
        } catch (Throwable) {
            return [];
        }

        // Cache only a real result — never a transient empty, which would blank the map for the TTL.
        if ($polygons !== []) {
            Cache::put($key, $polygons, now()->addDays(self::POLYGON_CACHE_DAYS));
        }

        return $polygons;
    }

    /**
     * The covered towns as tiered map points — only those with a geocoded lat/lng, largest-first so a
     * truncated payload keeps the biggest markets. The theme reveals them by tier on zoom.
     *
     * @return list<array{name: string, lat: float, lng: float, tier: string}>
     */
    private function cities(string $siteId): array
    {
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['name', 'lat', 'lng', 'size_tier', 'population']);

        $items = [];
        foreach ($areas as $area) {
            $name = trim((string) $area->name);
            if ($name === '') {
                continue;
            }
            $tier = (string) ($area->size_tier ?? '');
            $items[] = [
                'point' => [
                    'name' => $name,
                    'lat' => (float) $area->lat,
                    'lng' => (float) $area->lng,
                    'tier' => $tier !== '' ? $tier : 'small',
                ],
                'key' => [self::TIER_RANK[$tier] ?? 4, -1 * (int) ($area->population ?? 0), $name],
            ];
        }

        usort($items, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_map(fn (array $i): array => $i['point'], array_slice($items, 0, self::MAX_CITIES));
    }

    /**
     * A best-effort center point so the theme can seed the view even before it fits bounds — the mean of
     * the city points, else the mean of the polygon vertices. Null when there's nothing to average.
     *
     * @param  list<array{geo_id: string, name: string, rings: list<list<array{lat: float, lng: float}>>}>  $polygons
     * @param  list<array{name: string, lat: float, lng: float, tier: string}>  $cities
     * @return array{lat: float, lng: float}|null
     */
    private function center(array $polygons, array $cities): ?array
    {
        $lats = [];
        $lngs = [];

        foreach ($cities as $city) {
            $lats[] = $city['lat'];
            $lngs[] = $city['lng'];
        }

        if ($lats === []) {
            foreach ($polygons as $county) {
                foreach ($county['rings'] as $ring) {
                    foreach ($ring as $pt) {
                        $lats[] = $pt['lat'];
                        $lngs[] = $pt['lng'];
                    }
                }
            }
        }

        if ($lats === []) {
            return null;
        }

        return ['lat' => array_sum($lats) / count($lats), 'lng' => array_sum($lngs) / count($lngs)];
    }
}
