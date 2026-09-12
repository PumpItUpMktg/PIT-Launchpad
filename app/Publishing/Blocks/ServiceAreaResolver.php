<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Locations\Distance;
use App\Locations\TownGeoFallback;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\TownName;
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

    /** The most neighbours a town page's "nearby towns" list carries (nearest-first, within the radius). */
    private const NEIGHBOUR_MAX = 6;

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
    public function byCounty(string $siteId, ?string $locationId = null): array
    {
        $names = $this->countyNamesForSite($siteId); // geoId => county name
        if ($names === []) {
            return [];
        }

        $polygons = $this->countyPolygons(array_keys($names)); // geoId => rings
        [$byGeo, $byName] = $this->pageUrlIndexes($siteId);
        // Coverage-driven: the coverage row carries the geo_id, the town page is the anchorable counterpart.
        // Prefer the page found by geo_id, fall back to the name key while pages are still un-anchored. No
        // per-render tripwire report here (render path) — the batch launchpad:coverage-page-report over the
        // same served towns carries the trend + the geo_miss signal.
        $geo = new TownGeoFallback('ServiceAreaResolver', $siteId);

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['name', 'geo_id', 'type', 'lat', 'lng', 'size_tier', 'population', 'source_location_ids']);

        // §8.1 collapse: scope the coverage to a single physical Location — only the areas it serves
        // (via `source_location_ids`). Filtered in PHP (the JSON column round-trips to an array cast)
        // so it stays portable across the SQLite test driver and Postgres.
        if ($locationId !== null) {
            $areas = $areas->filter(
                fn (CoverageArea $a): bool => is_array($a->source_location_ids) && in_array($locationId, $a->source_location_ids, true)
            );
        }

        /** @var array<string, list<array{name: string, url: string, type: MunicipalityType, key: array{0: int, 1: int, 2: string}}>> $buckets */
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
                // Its own town page if one is built, else PLAIN TEXT (empty url) — an unbuilt town is not a
                // self-referencing link to the Areas page; the list fills in as tiers get built, and the
                // link plan has a real target to attach to. (The renderer shows empty-url as plain text.)
                'url' => $geo->resolveByCoverage((string) $area->geo_id, $this->key($name), $byGeo, $byName) ?? '',
                'type' => $area->type,
                'key' => [self::TIER_RANK[(string) $area->size_tier] ?? 4, -1 * (int) ($area->population ?? 0), $name],
            ];
        }

        // County name order, matching the county list below.
        $ordered = $names;
        asort($ordered);

        /** @var list<array{county: string, towns: list<array{name: string, url: string, type: MunicipalityType, label: string}>}> $groups */
        $groups = [];
        foreach (array_keys($ordered) as $geoId) {
            if (! isset($buckets[$geoId])) {
                continue;
            }
            $towns = $buckets[$geoId];
            usort($towns, fn (array $a, array $b): int => $a['key'] <=> $b['key']);
            // A9 fix: collapse same-name municipalities WITHIN a county to one row. A Census `place` and its
            // same-named `county_subdivision` (e.g. a Bethlehem place + a Bethlehem township in the same
            // county) are one town to a homeowner — rendering both read as a duplicate, and the old
            // "Twp/Boro" tie-breaker leaked the data model into public copy. Keep the first after the
            // largest-first sort (the higher tier / population), so the prominent row survives.
            $seen = [];
            $towns = array_values(array_filter($towns, function (array $t) use (&$seen): bool {
                $key = $this->key($t['name']);
                if ($key === '' || isset($seen[$key])) {
                    return $key === '';   // keep unnamed (shouldn't happen); collapse repeats of a real name
                }
                $seen[$key] = true;

                return true;
            }));
            $kept = [];
            foreach (array_slice($towns, 0, self::PER_COUNTY) as $town) {
                $kept[] = ['name' => $town['name'], 'url' => $town['url'], 'type' => $town['type'], 'label' => $town['name']];
            }
            $groups[] = ['county' => $names[$geoId], 'towns' => $kept];
        }

        $groups = $this->disambiguateLabels($groups);

        return array_map(fn (array $g): array => [
            'county' => $g['county'],
            'cities' => array_map(fn (array $t): array => ['label' => $t['label'], 'url' => $t['url']], $g['towns']),
        ], $groups);
    }

    /**
     * The NEAREST served towns to a TOWN page's own subject town — the town-page "nearby towns" list. A
     * flat, great-circle-distance-ranked set (up to {@see NEIGHBOUR_MAX} within
     * {@see neighbourRadiusMiles()}), scoped to the parent location's coverage, EXCLUDING the subject town.
     * Each neighbour links to its own PUBLISHED town page (plain text — empty url — when it has none, the
     * same link-or-plain rule the county list uses).
     *
     * Returns `towns: []` when the subject town has no resolvable centroid (un-anchored — geo_id absent AND
     * name unmatched) or when nothing is within range: the caller drops the section rather than invent an
     * arbitrary set or name a town 40 miles away. `county` is the subject town's OWN county (from its geo_id)
     * for the lead-in sentence, or null. This is deliberately distinct from {@see byCounty()} (the market
     * hub's full county-grouped list): a town page is about its town, so it names its actual neighbours.
     *
     * `anchored` reports whether the subject town resolved a centroid at all — false means un-anchored (the
     * section drops because we can't measure distance, distinct from an anchored town with nothing in range).
     * The preview command splits the two so the un-anchored count can be watched down as anchoring completes.
     *
     * @return array{county: ?string, towns: list<array{label: string, url: string}>, anchored: bool}
     */
    public function neighbours(string $siteId, string $parentLocationId, ?string $subjectGeoId, string $subjectName): array
    {
        $blank = ['county' => null, 'towns' => [], 'anchored' => false];

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get(['name', 'geo_id', 'lat', 'lng', 'source_location_ids']);

        // Subject-town centroid: geo_id first, then the town-name key (the same dual path the mesh uses).
        $byGeo = [];
        $byName = [];
        foreach ($areas as $a) {
            $entry = ['lat' => $a->lat !== null ? (float) $a->lat : null, 'lng' => $a->lng !== null ? (float) $a->lng : null];
            $gid = trim((string) $a->geo_id);
            if ($gid !== '') {
                $byGeo[$gid] = $entry;
            }
            $byName[$this->key((string) $a->name)] = $entry;
        }

        $origin = (new TownGeoFallback('ServiceAreaResolver.neighbours', $siteId))
            ->resolve($subjectGeoId, $this->key($subjectName), $byGeo, $byName);
        if ($origin === null || $origin['lat'] === null || $origin['lng'] === null) {
            return $blank; // un-anchored subject town → no honest neighbours to name
        }

        $subjectKey = $this->key($subjectName);
        $radius = $this->neighbourRadiusMiles();

        // Candidates: the parent's coverage rows with coordinates, minus the subject; nearest-first within
        // the radius, then deduped by name (a place + its same-named MCD are one town), capped.
        $near = [];
        foreach ($areas as $a) {
            if (! is_array($a->source_location_ids) || ! in_array($parentLocationId, $a->source_location_ids, true)) {
                continue;
            }
            if ($a->lat === null || $a->lng === null) {
                continue;
            }
            $name = trim((string) $a->name);
            if ($name === '' || $this->key($name) === $subjectKey) {
                continue;
            }
            $near[] = [
                'name' => $name,
                'geo_id' => trim((string) $a->geo_id),
                'miles' => Distance::miles($origin['lat'], $origin['lng'], (float) $a->lat, (float) $a->lng),
            ];
        }
        usort($near, fn (array $x, array $y): int => $x['miles'] <=> $y['miles']);

        $seen = [];
        $picked = [];
        foreach ($near as $n) {
            if ($n['miles'] > $radius) {
                break; // sorted nearest-first — nothing beyond here is in range
            }
            $k = $this->key($n['name']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $picked[] = $n;
            if (count($picked) >= self::NEIGHBOUR_MAX) {
                break;
            }
        }
        if ($picked === []) {
            // Anchored (we had a centroid) but no served town within range — the section still drops, but
            // this is a real coverage-density fact, not a missing anchor.
            return ['county' => $this->subjectCounty($siteId, $subjectGeoId), 'towns' => [], 'anchored' => true];
        }

        // Link each neighbour to its PUBLISHED town page (plain text otherwise).
        [$pageGeo, $pageName] = $this->pageUrlIndexes($siteId);
        $links = new TownGeoFallback('ServiceAreaResolver.neighbourLinks', $siteId);
        $towns = [];
        foreach ($picked as $n) {
            $url = $links->resolveByCoverage($n['geo_id'] !== '' ? $n['geo_id'] : null, $this->key($n['name']), $pageGeo, $pageName);
            $towns[] = ['label' => $n['name'], 'url' => is_string($url) ? $url : ''];
        }
        $links->report();

        return ['county' => $this->subjectCounty($siteId, $subjectGeoId), 'towns' => $towns, 'anchored' => true];
    }

    /**
     * The centroid (lat/lng) of a subject TOWN, resolved from CoverageArea by geo_id then name-key — the
     * same resolution {@see neighbours()} uses to measure distance, exposed for other proximity consumers
     * (the "jobs near {town}" selection). Returns nulls when the town is un-anchored (no geo_id and no
     * name match) — the caller then has no origin to rank from, exactly as neighbours() drops.
     *
     * @return array{lat: ?float, lng: ?float}
     */
    public function subjectCentroid(string $siteId, ?string $subjectGeoId, string $subjectName): array
    {
        $byGeo = [];
        $byName = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->get(['name', 'geo_id', 'lat', 'lng']) as $a) {
            $entry = ['lat' => $a->lat !== null ? (float) $a->lat : null, 'lng' => $a->lng !== null ? (float) $a->lng : null];
            $gid = trim((string) $a->geo_id);
            if ($gid !== '') {
                $byGeo[$gid] = $entry;
            }
            $byName[$this->key((string) $a->name)] = $entry;
        }

        $origin = (new TownGeoFallback('ServiceAreaResolver.subjectCentroid', $siteId))
            ->resolve($subjectGeoId, $this->key($subjectName), $byGeo, $byName);

        return is_array($origin) ? ['lat' => $origin['lat'], 'lng' => $origin['lng']] : ['lat' => null, 'lng' => null];
    }

    /** The town's OWN county name (its geo_id's 5-digit county prefix, if it's one the site serves), or null. */
    private function subjectCounty(string $siteId, ?string $subjectGeoId): ?string
    {
        $gid = trim((string) $subjectGeoId);
        if (strlen($gid) < 5) {
            return null;
        }

        return $this->countyNamesForSite($siteId)[substr($gid, 0, 5)] ?? null;
    }

    /** The neighbour radius (miles) — shared with the internal-link mesh so copy + links never drift. */
    private function neighbourRadiusMiles(): float
    {
        return (float) config('launchpad.link_plan.neighbour_radius_miles', 20.0);
    }

    /**
     * Qualify a town name that appears in MORE THAN ONE county with its county — "Washington (Warren
     * County)" vs "Washington (Hunterdon County)" — so two genuinely different towns that share a name
     * across the served counties read distinctly. A name unique across the grid is left exactly as it is.
     *
     * Same-name municipalities WITHIN one county are no longer disambiguated here — they are collapsed to a
     * single row upstream in {@see byCounty()} (a place + its same-named MCD are one town to a homeowner).
     * The old municipal-type tie-breaker ("(County, Twp/Boro)") is gone: it leaked an internal model
     * distinction into public copy, which is never something a customer should read.
     *
     * @param  list<array{county: string, towns: list<array{name: string, url: string, type: MunicipalityType, label: string}>}>  $groups
     * @return list<array{county: string, towns: list<array{name: string, url: string, type: MunicipalityType, label: string}>}>
     */
    private function disambiguateLabels(array $groups): array
    {
        $nameCounts = [];
        foreach ($groups as $g) {
            foreach ($g['towns'] as $t) {
                $nameCounts[$this->key($t['name'])] = ($nameCounts[$this->key($t['name'])] ?? 0) + 1;
            }
        }

        // County qualifier for a name that appears in more than one county (after the within-county
        // collapse, any remaining repeat of a name is necessarily in a DIFFERENT county).
        foreach ($groups as $gi => $g) {
            foreach ($g['towns'] as $ti => $t) {
                if (($nameCounts[$this->key($t['name'])] ?? 0) > 1 && trim($g['county']) !== '') {
                    $groups[$gi]['towns'][$ti]['label'] = $t['name'].' ('.$g['county'].')';
                }
            }
        }

        return $groups;
    }

    /**
     * @return array{counties: list<string>, cities: list<array{label: string, url: string}>, more: int}
     */
    public function resolve(string $siteId): array
    {
        [$names, $more] = $this->cities($siteId);

        // Attach a REAL town-page link where one exists (every link resolves to a real page); a town
        // with no location page renders as a plain pill. Coverage-driven: the coverage carries the geo_id
        // (looked up by name from $coverageGeo — the town names come from `cities()`, which flattens both
        // the coverage and the market-fallback sets), the town page is the anchorable counterpart. Prefer
        // the page found by geo_id, fall back to the name key while pages are still un-anchored. No
        // per-render tripwire report (render path) — the batch launchpad:coverage-page-report carries it.
        [$byGeo, $byName] = $this->pageUrlIndexes($siteId);
        $coverageGeo = $this->coverageGeoByName($siteId);
        $geo = new TownGeoFallback('ServiceAreaResolver', $siteId);
        $cities = array_map(fn (string $name): array => [
            'label' => $name,
            'url' => $geo->resolveByCoverage($coverageGeo[$this->key($name)] ?? null, $this->key($name), $byGeo, $byName) ?? '',
        ], $names);

        return [
            'counties' => $this->counties($siteId),
            'cities' => $cities,
            'more' => $more,
        ];
    }

    /**
     * The published location pages indexed BOTH ways for the geo-first join: by their census `geo_id` (the
     * anchored ones only) and by the town-name key (all of them, carrying whether each is anchored). Real
     * pages only — a town without a location page just won't be in the map, so it links to nothing.
     *
     * @return array{0: array<string, string>, 1: array<string, array{value: string, anchored: bool}>}
     */
    private function pageUrlIndexes(string $siteId): array
    {
        $domain = Site::find($siteId)?->domain_url;
        $home = is_string($domain) && trim($domain) !== '' ? rtrim($domain, '/').'/' : '/';

        // Only LIVE pages are linkable — status published AND actually pushed (wp_post_id set), the same
        // "live page" rule the service-card link rule uses. Linking a planned-but-unpublished town page
        // would manufacture a dead link, the exact class the coverage cleanup is removing.
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotNull('slug')
            ->get(['title', 'slug', 'geo_id']);

        $byGeo = [];
        $byName = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->title);
            $slug = trim((string) $page->slug);
            if ($title === '' || $slug === '') {
                continue;
            }
            $url = $home.\App\Build\Permalinks::slugPath($slug);
            $geoId = trim((string) $page->geo_id);
            if ($geoId !== '') {
                $byGeo[$geoId] = $url;
            }
            // Name key is last-wins (matching the prior locationUrls behavior); anchored iff it has a geo_id.
            $byName[$this->key($title)] = ['value' => $url, 'anchored' => $geoId !== ''];
        }

        return [$byGeo, $byName];
    }

    /**
     * town-name key => the coverage row's census `geo_id`, so the name-driven {@see resolve()} can still go
     * geo-first (the town names it carries come from `cities()`, which flattens the coverage set). First-wins
     * per name; coverage rows with no geo_id are skipped (they resolve on the name path, as before).
     *
     * @return array<string, string>
     */
    private function coverageGeoByName(string $siteId): array
    {
        $map = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->get(['name', 'geo_id']) as $area) {
            $key = $this->key(trim((string) $area->name));
            $geoId = trim((string) $area->geo_id);
            if ($key !== '' && $geoId !== '' && ! isset($map[$key])) {
                $map[$key] = $geoId;
            }
        }

        return $map;
    }

    /**
     * The town-name key both sides of the location-link join normalize to — the shared {@see TownName}
     * helper (strips a trailing ", ST" so a location page titled "{City}, {ST}" matches a bare
     * {@see CoverageArea} "{City}"). Without it EVERY town missed its own page and fell back to the
     * "Areas we serve" page.
     */
    private function key(string $name): string
    {
        return TownName::key($name);
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
