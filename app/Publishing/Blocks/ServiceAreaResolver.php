<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves the "Areas we serve" section's data for a site: the COUNTIES served (named), then the towns
 * ordered LARGEST-first (major → large → medium → small) with the long tail truncated so the section
 * reads as a hierarchy, not a crowded tag cloud.
 *
 * County names aren't persisted (only the selected `Location.county_geoids`), so they're resolved the
 * same way the onboarding Territory screen does — {@see MunicipalityGazetteer::countiesInState()} —
 * cached per state and best-effort: any gazetteer failure just drops the county lead-in, the towns
 * still render. Towns come from §1 `CoverageArea` (which carries the census `size_tier` + population);
 * a tenant with no coverage set yet falls back to its page-worthy `Market`s (prior behavior).
 */
final class ServiceAreaResolver
{
    /** Largest-first ordering of the census size tiers; an ungrouped town sorts last. */
    private const TIER_RANK = ['major' => 0, 'large' => 1, 'medium' => 2, 'small' => 3];

    private const MAX_CITIES = 18;

    /** Largest towns shown per county in the grouped "major cities" column. */
    private const PER_COUNTY = 6;

    private const COUNTY_CACHE_DAYS = 30;

    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * The served counties each paired with their LARGEST towns — for the areas section's "major cities
     * from each county" column. A town is assigned to its county by census GEOID (a county subdivision
     * carries its county in the first 5 digits), else by which served-county polygon contains its point
     * (offline, using the same cached polygons the map draws). Largest-first, capped per county; counties
     * ordered by name to match the county list. Best-effort: any gazetteer failure yields [].
     *
     * @return list<array{county: string, cities: list<array{label: string, url: string}>}>
     */
    public function byCounty(string $siteId): array
    {
        $names = $this->countyNamesForSite($siteId); // geoId => county name
        if ($names === []) {
            return [];
        }

        $polygons = $this->countyPolygons(array_keys($names)); // geoId => rings
        $urls = $this->locationUrls($siteId);

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['name', 'geo_id', 'type', 'lat', 'lng', 'size_tier', 'population']);

        /** @var array<string, list<array{name: string, url: string, key: array{0: int, 1: int, 2: string}}>> $buckets */
        $buckets = [];
        foreach ($areas as $area) {
            $name = trim((string) $area->name);
            if ($name === '') {
                continue;
            }
            $geoId = $this->assignCounty($area, $names, $polygons);
            if ($geoId === null) {
                continue; // no confident county → it still plots on the map, just not in a county group
            }
            $buckets[$geoId][] = [
                'name' => $name,
                'url' => $urls[$this->key($name)] ?? '',
                'key' => [self::TIER_RANK[(string) $area->size_tier] ?? 4, -1 * (int) ($area->population ?? 0), $name],
            ];
        }

        // County name order, matching the county list below.
        $ordered = $names;
        asort($ordered);

        $out = [];
        foreach (array_keys($ordered) as $geoId) {
            if (! isset($buckets[$geoId])) {
                continue;
            }
            $towns = $buckets[$geoId];
            usort($towns, fn (array $a, array $b): int => $a['key'] <=> $b['key']);
            $cities = [];
            foreach (array_slice($towns, 0, self::PER_COUNTY) as $town) {
                $cities[] = ['label' => $town['name'], 'url' => $town['url']];
            }
            $out[] = ['county' => $names[$geoId], 'cities' => $cities];
        }

        return $out;
    }

    /**
     * @return array{counties: list<string>, cities: list<array{label: string, url: string}>, more: int}
     */
    public function resolve(string $siteId): array
    {
        [$names, $more] = $this->cities($siteId);

        // Attach a REAL town-page link where one exists (every link resolves to a real page); a town
        // with no location page renders as a plain pill.
        $urls = $this->locationUrls($siteId);
        $cities = array_map(fn (string $name): array => [
            'label' => $name,
            'url' => $urls[$this->key($name)] ?? '',
        ], $names);

        return [
            'counties' => $this->counties($siteId),
            'cities' => $cities,
            'more' => $more,
        ];
    }

    /**
     * town name (lower-cased) => its published location-page URL. Real pages only — a town without a
     * location page just won't be in the map, so it links to nothing.
     *
     * @return array<string, string>
     */
    private function locationUrls(string $siteId): array
    {
        $domain = Site::find($siteId)?->domain_url;
        $home = is_string($domain) && trim($domain) !== '' ? rtrim($domain, '/').'/' : '/';

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('slug')
            ->get(['title', 'slug']);

        $map = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->title);
            $slug = trim((string) $page->slug);
            if ($title === '' || $slug === '') {
                continue;
            }
            $map[$this->key($title)] = $home.ltrim($slug, '/');
        }

        return $map;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * The named counties the site serves — from the selected `county_geoids`, resolved to names via the
     * gazetteer (cached per state). Best-effort: on any failure or with no selection, returns [].
     *
     * @return list<string>
     */
    private function counties(string $siteId): array
    {
        $names = array_values($this->countyNamesForSite($siteId));
        sort($names);

        return $names;
    }

    /**
     * geoId => county name for every county the site serves. The shared resolver behind {@see counties()}
     * and {@see byCounty()}. Best-effort: any failure or no selection → [].
     *
     * @return array<string, string>
     */
    private function countyNamesForSite(string $siteId): array
    {
        $geoIds = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->pluck('county_geoids')
            ->flatMap(fn ($v): array => is_array($v) ? $v : [])
            ->map(fn ($g): string => (string) $g)
            ->filter(fn (string $g): bool => strlen($g) >= 5)
            ->unique()
            ->values();

        if ($geoIds->isEmpty()) {
            return [];
        }

        try {
            $names = [];
            foreach ($geoIds->groupBy(fn (string $g): string => substr($g, 0, 2)) as $stateFips => $stateGeoIds) {
                $map = $this->countyNames((string) $stateFips);
                foreach ($stateGeoIds as $geoId) {
                    if (isset($map[$geoId]) && $map[$geoId] !== '') {
                        $names[(string) $geoId] = $map[$geoId];
                    }
                }
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * geoId => boundary rings for the served counties, cached 30d under the SAME key the map uses (so it's
     * a shared hit, not a second fetch). Best-effort: a gazetteer failure yields [] and grouping falls back
     * to the GEOID prefix alone.
     *
     * @param  list<string>  $geoIds
     * @return array<string, list<list<array{lat: float, lng: float}>>>
     */
    private function countyPolygons(array $geoIds): array
    {
        $geoIds = array_values(array_filter(array_map('strval', $geoIds), fn (string $g): bool => $g !== ''));
        if ($geoIds === []) {
            return [];
        }

        sort($geoIds);
        $key = 'lp.county_polygons.'.md5(implode(',', $geoIds));

        $cached = Cache::get($key);
        $polys = is_array($cached) ? $cached : null;
        if ($polys === null) {
            try {
                $polys = $this->gazetteer->countyPolygons($geoIds);
            } catch (Throwable) {
                return [];
            }
            if ($polys !== []) {
                Cache::put($key, $polys, now()->addDays(self::COUNTY_CACHE_DAYS));
            }
        }

        $out = [];
        foreach ($polys as $poly) {
            if (isset($poly['geo_id'], $poly['rings']) && is_array($poly['rings'])) {
                $out[(string) $poly['geo_id']] = $poly['rings'];
            }
        }

        return $out;
    }

    /**
     * The served-county GEOID a coverage town belongs to: a county subdivision carries its county in the
     * first 5 GEOID digits; anything else is placed by which served-county polygon contains its point.
     * Null when neither resolves confidently (the town still plots on the map, just ungrouped).
     *
     * @param  array<string, string>  $names  served geoId => name
     * @param  array<string, list<list<array{lat: float, lng: float}>>>  $polygons
     */
    private function assignCounty(CoverageArea $area, array $names, array $polygons): ?string
    {
        if ($area->type === MunicipalityType::CountySubdivision) {
            $county = substr((string) $area->geo_id, 0, 5);
            if (isset($names[$county])) {
                return $county;
            }
        }

        if ($area->lat !== null && $area->lng !== null) {
            foreach ($polygons as $geoId => $rings) {
                if ($this->pointInRings((float) $area->lat, (float) $area->lng, $rings)) {
                    return (string) $geoId;
                }
            }
        }

        return null;
    }

    /**
     * Ray-casting point-in-polygon over a county's rings (lat = y, lng = x). Good enough to bucket a town
     * into the county that contains it; exact boundary ties don't matter here.
     *
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     */
    private function pointInRings(float $lat, float $lng, array $rings): bool
    {
        $inside = false;
        foreach ($rings as $ring) {
            $n = count($ring);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $yi = $ring[$i]['lat'];
                $xi = $ring[$i]['lng'];
                $yj = $ring[$j]['lat'];
                $xj = $ring[$j]['lng'];
                $denom = ($yj - $yi) !== 0.0 ? ($yj - $yi) : 1e-12;
                if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / $denom + $xi)) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }

    /**
     * geo_id => county name for a state, cached (counties don't move). The gazetteer is the same one the
     * onboarding county multi-select uses.
     *
     * @return array<string, string>
     */
    private function countyNames(string $stateFips): array
    {
        return Cache::remember(
            "lp.county_names.{$stateFips}",
            now()->addDays(self::COUNTY_CACHE_DAYS),
            function () use ($stateFips): array {
                $map = [];
                foreach ($this->gazetteer->countiesInState($stateFips) as $county) {
                    /** @var County $county */
                    $map[$county->geoId] = $county->name;
                }

                return $map;
            },
        );
    }

    /**
     * The towns to show, largest-first, plus the count of the truncated tail. From `CoverageArea`
     * (census size tiers); falls back to page-worthy `Market`s when no coverage is set.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function cities(string $siteId): array
    {
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['name', 'size_tier', 'population']);

        if ($areas->isEmpty()) {
            return $this->marketFallback($siteId);
        }

        // Sort key per town: tier rank (major=0 … small=3, ungrouped last), then population DESC
        // (negated), then name — largest-first, deterministically.
        $items = [];
        foreach ($areas as $area) {
            $name = trim((string) $area->name);
            if ($name === '') {
                continue;
            }
            $items[] = [
                'name' => $name,
                'key' => [self::TIER_RANK[(string) $area->size_tier] ?? 4, -1 * (int) ($area->population ?? 0), $name],
            ];
        }

        usort($items, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        $sorted = array_values(array_unique(array_map(fn (array $i): string => $i['name'], $items)));

        return [array_slice($sorted, 0, self::MAX_CITIES), max(0, count($sorted) - self::MAX_CITIES)];
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private function marketFallback(string $siteId): array
    {
        $names = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->orderByRaw('CASE WHEN tier = ? THEN 0 ELSE 1 END', ['priority'])
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '')
            ->unique()
            ->values()
            ->all();

        return [array_slice($names, 0, self::MAX_CITIES), max(0, count($names) - self::MAX_CITIES)];
    }
}
