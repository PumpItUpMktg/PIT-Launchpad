<?php

namespace App\OpsConsole;

use App\ContentEngine\Reconcile\LocalTownCoherence;
use App\ContentEngine\Reconcile\LocalTownMatcher;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * The brick-and-mortar town model for the console blog filters: the towns a site's STOREFRONT locations
 * ({@see Location::$is_storefront}) cover, grouped by county so a mostly-one-county tenant can microtarget
 * without scrolling a giant flat list. It also answers "which storefront towns does this post cover?" —
 * authoritatively for a published post ({@see ContentTown} tags) and by a whole-word title+body scan for a
 * still-in-review draft.
 *
 * County naming: county GEOIDs are persisted on the Location ({@see Location::$home_county_geoid} /
 * `county_geoids`); the human name comes from the Census gazetteer, resolved once per state and CACHED
 * (state→county names change ~never), so a dropdown never hits the network per render. A town's county is
 * the GEOID prefix for a county-subdivision, else the owning storefront's home county — the same
 * best-effort "soft rule" the physical-locations directory uses (place GEOIDs carry no county).
 */
class StorefrontTowns
{
    /** Cache the per-state county-name map this long — TIGERweb county names are effectively static. */
    private const COUNTY_NAME_TTL_DAYS = 30;

    public function __construct(
        private readonly MunicipalityGazetteer $gazetteer,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Storefront coverage towns grouped by county, county name-ordered, towns name-ordered.
     *
     * @return list<array{geoid: string, name: string, towns: list<array{key: string, display: string}>}>
     */
    public function counties(Site $site): array
    {
        $storefronts = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('is_storefront', true)->get();
        if ($storefronts->isEmpty()) {
            return [];
        }

        $storefrontIds = $storefronts->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $homeByCounty = [];   // county geoid => the storefront home county (for place-GEOID fallback)
        $servedCounties = []; // every county a storefront names
        foreach ($storefronts as $s) {
            $home = trim((string) $s->home_county_geoid);
            foreach (array_merge($home !== '' ? [$home] : [], is_array($s->county_geoids) ? $s->county_geoids : []) as $g) {
                $g = trim((string) $g);
                if (strlen($g) >= 5) {
                    $servedCounties[$g] = true;
                    $homeByCounty[$s->id] ??= $home !== '' ? $home : $g;
                }
            }
            if ($home !== '') {
                $homeByCounty[$s->id] = $home;
            }
        }

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get()
            ->filter(fn (CoverageArea $a): bool => array_intersect($storefrontIds, is_array($a->source_location_ids) ? $a->source_location_ids : []) !== []);

        /** @var array<string, array{geoid: string, towns: array<string, string>}> $byCounty */
        $byCounty = [];
        foreach ($towns as $town) {
            $county = $this->countyFor($town, $storefrontIds, $homeByCounty, array_keys($servedCounties));
            if ($county === null) {
                continue;
            }
            $key = $this->key((string) $town->name);
            $display = trim((string) $town->name).($town->state ? ", {$town->state}" : '');
            $byCounty[$county]['geoid'] = $county;
            $byCounty[$county]['towns'][$key] = $display;
        }

        $names = $this->countyNames(array_keys($byCounty));

        $out = [];
        foreach ($byCounty as $geoid => $data) {
            $townList = [];
            foreach ($data['towns'] as $key => $display) {
                $townList[] = ['key' => $key, 'display' => $display];
            }
            usort($townList, fn (array $a, array $b): int => strcasecmp($a['display'], $b['display']));
            $out[] = ['geoid' => (string) $geoid, 'name' => $names[$geoid] ?? $this->fallbackName((string) $geoid), 'towns' => $townList];
        }
        usort($out, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The counties a SINGLE storefront serves, as human names — its geocoded home county plus every
     * owner-selected `county_geoids`, de-duped and name-ordered. Names come from the same cached Census
     * gazetteer the county grouping uses (degrading to a "County NNN" label if a name can't be resolved).
     * This is the "counties served" chip row on the storefront card; empty when the location names no county.
     *
     * @return list<string>
     */
    public function servedCountyNames(Location $storefront): array
    {
        $geoids = [];
        $home = trim((string) $storefront->home_county_geoid);
        if ($home !== '') {
            $geoids[] = $home;
        }
        foreach (is_array($storefront->county_geoids) ? $storefront->county_geoids : [] as $g) {
            $geoids[] = trim((string) $g);
        }
        $geoids = array_values(array_unique(array_filter($geoids, fn (string $g): bool => strlen($g) >= 5)));
        if ($geoids === []) {
            return [];
        }

        $names = $this->countyNames($geoids);
        $out = array_values(array_unique(array_map(fn (string $g): string => $names[$g] ?? $this->fallbackName($g), $geoids)));
        usort($out, fn (string $a, string $b): int => strcasecmp($a, $b));

        return $out;
    }

    /** The storefront town keys in a given county (for the town dropdown / county-level filter). */
    public function keysInCounty(Site $site, string $countyGeoid): array
    {
        foreach ($this->counties($site) as $county) {
            if ($county['geoid'] === $countyGeoid) {
                return array_map(fn (array $t): string => $t['key'], $county['towns']);
            }
        }

        return [];
    }

    /** Every storefront town key for the site. */
    public function allKeys(Site $site): array
    {
        $keys = [];
        foreach ($this->counties($site) as $county) {
            foreach ($county['towns'] as $t) {
                $keys[] = $t['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The storefront towns a post covers (display names) — authoritative via ContentTown tags for a
     * published post, else a whole-word title+body scan against the town displays. Empty = covers none.
     *
     * @param  array<string, string>  $townDisplays  town key => display name (the candidate storefront towns)
     * @return list<string>
     */
    public function matchTowns(Content $post, array $townDisplays): array
    {
        if ($townDisplays === []) {
            return [];
        }

        $meta = $this->townMeta($post);   // key => [name, county, state]

        $tagged = ContentTown::query()->where('content_id', $post->id)->pluck('town')->all();
        if ($tagged !== []) {
            // Authoritative tags (the tagger already coheres + caps these); keep those in the visible set.
            $matched = [];
            $pos = 0;
            foreach ($tagged as $key) {
                if (isset($townDisplays[$key])) {
                    $matched[] = ['key' => $key, 'display' => $townDisplays[$key], 'county' => $meta[$key]['county'] ?? null, 'state' => $meta[$key]['state'] ?? $this->stateOf($townDisplays[$key]), 'pos' => $pos++];
                }
            }
        } else {
            // No tags yet (draft/approved) — scan the copy, false-positive-guarded (§ LocalTownMatcher).
            $towns = [];
            foreach ($townDisplays as $key => $display) {
                $towns[] = [
                    'key' => (string) $key, 'display' => $display,
                    'name' => $meta[$key]['name'] ?? $this->nameOf($display),
                    'county' => $meta[$key]['county'] ?? null, 'state' => $meta[$key]['state'] ?? $this->stateOf($display),
                ];
            }
            $matched = LocalTownMatcher::scan((string) $post->title.' '.(string) $post->body, $towns);
        }

        return array_map(fn (array $m): string => $m['display'], LocalTownCoherence::select($matched));
    }

    /**
     * County (GEOID) + state + bare name per storefront town key, for the coherence/false-positive
     * pass. Resolved from the same county grouping the filters use.
     *
     * @return array<string, array{name: string, county: ?string, state: ?string}>
     */
    private function townMeta(Content $post): array
    {
        $site = $post->site ?? Site::query()->find($post->site_id);
        if (! $site instanceof Site) {
            return [];
        }

        $meta = [];
        foreach ($this->counties($site) as $county) {
            foreach ($county['towns'] as $t) {
                $meta[$t['key']] = ['name' => $this->nameOf($t['display']), 'county' => $county['geoid'], 'state' => $this->stateOf($t['display'])];
            }
        }

        return $meta;
    }

    /** The town name without a trailing ", ST" state suffix. */
    private function nameOf(string $display): string
    {
        return trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($display)));
    }

    /** The lowercased two-letter state from a ", ST" suffix, or null. */
    private function stateOf(string $display): ?string
    {
        return preg_match('/,\s*([A-Za-z]{2})\.?$/', trim($display), $m) === 1 ? mb_strtolower($m[1]) : null;
    }

    /**
     * Whether a post covers any of the given storefront town keys.
     *
     * @param  array<string, string>  $townDisplays  town key => display name
     */
    public function postCovers(Content $post, array $townDisplays): bool
    {
        return $this->matchTowns($post, $townDisplays) !== [];
    }

    /** The storefront town keys (+displays) to match against, for a county/town selection. */
    public function targetTowns(Site $site, ?string $countyGeoid, ?string $townKey): array
    {
        $displays = [];
        foreach ($this->counties($site) as $county) {
            if ($countyGeoid !== null && $county['geoid'] !== $countyGeoid) {
                continue;
            }
            foreach ($county['towns'] as $t) {
                if ($townKey !== null && $t['key'] !== $townKey) {
                    continue;
                }
                $displays[$t['key']] = $t['display'];
            }
        }

        return $displays;
    }

    /**
     * @param  list<string>  $storefrontIds
     * @param  array<string, string>  $homeByLocation  storefront location id => home county geoid
     * @param  list<string>  $servedCounties
     */
    private function countyFor(CoverageArea $town, array $storefrontIds, array $homeByLocation, array $servedCounties): ?string
    {
        // County subdivisions carry their county in the GEOID prefix (STATE+COUNTY = first 5).
        $geoId = (string) $town->geo_id;
        if (strlen($geoId) >= 5) {
            $prefix = substr($geoId, 0, 5);
            if (in_array($prefix, $servedCounties, true)) {
                return $prefix;
            }
        }

        // Place GEOID (no county) — attribute it to an owning storefront's home county.
        $owners = array_values(array_intersect($storefrontIds, is_array($town->source_location_ids) ? $town->source_location_ids : []));
        foreach ($owners as $ownerId) {
            $home = trim((string) ($homeByLocation[$ownerId] ?? ''));
            if ($home !== '') {
                return $home;
            }
        }

        return $servedCounties[0] ?? null;
    }

    /**
     * @param  list<string>  $countyGeoids
     * @return array<string, string> geoid => county name (cached per state; degrades to a fallback label)
     */
    private function countyNames(array $countyGeoids): array
    {
        $states = array_unique(array_map(fn (string $g): string => substr($g, 0, 2), $countyGeoids));

        $names = [];
        foreach ($states as $stateFips) {
            $map = $this->cache->remember(
                "storefront_county_names:{$stateFips}",
                now()->addDays(self::COUNTY_NAME_TTL_DAYS),
                function () use ($stateFips): array {
                    try {
                        $out = [];
                        foreach ($this->gazetteer->countiesInState($stateFips) as $county) {
                            $out[$county->geoId] = $county->name;
                        }

                        return $out;
                    } catch (Throwable) {
                        return [];
                    }
                },
            );
            $names += $map;
        }

        return $names;
    }

    private function fallbackName(string $countyGeoid): string
    {
        return 'County '.substr($countyGeoid, 2);
    }

    /** The normalized town key — matches {@see ContentTown} (lowercased, no ", ST"). */
    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
