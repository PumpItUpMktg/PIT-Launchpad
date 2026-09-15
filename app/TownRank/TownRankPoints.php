<?php

namespace App\TownRank;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\GeoGrid\CoverageGrid;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * The SITE-WIDE town point-source for town-rank scanning (§ Town Rank): the union of every location's
 * coverage towns ({@see CoverageGrid} — each municipality in the served counties, geocoded), de-duplicated,
 * population-descending. Census placeholder rows ("County subdivisions not defined") and towns with no
 * population are left out: nobody searches from them. Each point carries the bare `name` (what a query is
 * built from) and a display `label` that disambiguates same-named towns ({@see TownLabels}), plus the town's
 * published page (matched on the shared Census GEOID, never by name) so the report can separate "we rank
 * with a page" from "we rank with nothing" from "no page, no rank" — the last being where to build next.
 *
 * Operator context crosses tenants, so the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class TownRankPoints
{
    /**
     * Per-request memo keyed by site id. The town list is read by the report, the board's map, the
     * estimate and the scanner — a card wall of K keywords asked for it K+1 times, each a full walk of the
     * site's coverage areas, which is what pushed the page past the gateway timeout. The service is bound
     * `scoped`, so every caller in one request shares this.
     *
     * @var array<string, list<array{coverage_area_id: string, geo_id: string|null, name: string, label: string, state: string|null, lat: float, lng: float, population: int, page_slug: string|null, page_url: string|null, page_match: string|null}>>
     */
    private array $memo = [];

    public function __construct(
        private readonly CoverageGrid $coverage,
        private readonly TownLabels $labels,
    ) {}

    /**
     * `page_match` says how the page was found: `geoid` (the anchor join) or `slug` (a published location page
     * whose slug is this town's — the page exists but its GEOID is a different Census form than the coverage
     * row's, or it is un-anchored), else null.
     *
     * @return list<array{coverage_area_id: string, geo_id: string|null, name: string, label: string, state: string|null, lat: float, lng: float, population: int, page_slug: string|null, page_url: string|null, page_match: string|null}>
     */
    public function forSite(Site $site): array
    {
        $siteId = (string) $site->id;
        if (isset($this->memo[$siteId])) {
            return $this->memo[$siteId];
        }

        return $this->memo[$siteId] = $this->build($site);
    }

    /** Drop the per-request memo (tests, or a long-lived process that changed coverage). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return list<array{coverage_area_id: string, geo_id: string|null, name: string, label: string, state: string|null, lat: float, lng: float, population: int, page_slug: string|null, page_url: string|null, page_match: string|null}> */
    private function build(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        $byArea = [];
        foreach ($this->coverage->pointsForMany($locations) as $points) {
            foreach ($points as $point) {
                if ($point['population'] <= 0 || preg_match('/not defined/i', $point['label']) === 1) {
                    continue;   // a Census placeholder or an unpopulated pseudo-area — nobody searches from it
                }
                $byArea[$point['coverage_area_id']] ??= $point;
            }
        }
        if ($byArea === []) {
            return [];
        }

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', array_keys($byArea))
            ->get(['id', 'geo_id', 'state'])
            ->keyBy('id');

        $labelInput = [];
        foreach ($byArea as $id => $point) {
            $area = $areas->get($id);
            $labelInput[] = ['id' => (string) $id, 'name' => $point['label'], 'state' => $area?->state, 'geo_id' => $area !== null && $area->geo_id !== '' ? $area->geo_id : null];
        }
        $labels = $this->labels->for($labelInput);

        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['slug', 'geo_id']);
        $pages = $published->whereNotNull('geo_id')->pluck('slug', 'geo_id');
        $slugs = $published->pluck('slug')->map(fn ($s): string => (string) $s)->flip();

        $domain = is_string($site->domain_url) ? rtrim($site->domain_url, '/') : '';

        $points = [];
        foreach ($byArea as $id => $point) {
            $area = $areas->get($id);
            $geoId = $area !== null && $area->geo_id !== '' ? $area->geo_id : null;
            $state = $area?->state;
            $match = null;
            $slug = null;
            if ($geoId !== null && $pages->has($geoId)) {
                $slug = (string) $pages->get($geoId);
                $match = 'geoid';
            } else {
                $townSlug = Str::slug($point['label']).($state !== null && $state !== '' ? '-'.strtolower($state) : '');
                if ($slugs->has($townSlug)) {
                    $slug = $townSlug;
                    $match = 'slug';
                }
            }
            $points[] = [
                'coverage_area_id' => (string) $id,
                'geo_id' => $geoId,
                'name' => $point['label'],
                'label' => $labels[(string) $id] ?? $point['label'],
                'state' => $state !== null && $state !== '' ? $state : null,
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'population' => $point['population'],
                'page_slug' => $slug,
                'page_url' => $slug !== null && $domain !== '' ? $domain.'/'.ltrim($slug, '/') : null,
                'page_match' => $match,
            ];
        }

        usort($points, fn (array $a, array $b): int => $b['population'] <=> $a['population']);

        return $points;
    }
}
