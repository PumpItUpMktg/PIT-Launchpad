<?php

namespace App\Publishing\Blocks;

use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
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

    private const COUNTY_CACHE_DAYS = 30;

    public function __construct(private readonly MunicipalityGazetteer $gazetteer) {}

    /**
     * @return array{counties: list<string>, cities: list<string>, more: int}
     */
    public function resolve(string $siteId): array
    {
        [$cities, $more] = $this->cities($siteId);

        return [
            'counties' => $this->counties($siteId),
            'cities' => $cities,
            'more' => $more,
        ];
    }

    /**
     * The named counties the site serves — from the selected `county_geoids`, resolved to names via the
     * gazetteer (cached per state). Best-effort: on any failure or with no selection, returns [].
     *
     * @return list<string>
     */
    private function counties(string $siteId): array
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
                        $names[$map[$geoId]] = true;
                    }
                }
            }

            $out = array_keys($names);
            sort($out);

            return $out;
        } catch (Throwable) {
            return [];
        }
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
