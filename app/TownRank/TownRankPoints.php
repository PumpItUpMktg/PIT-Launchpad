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

/**
 * The SITE-WIDE town point-source for town-rank scanning (§ Town Rank): the union of every location's
 * coverage towns ({@see CoverageGrid} — each municipality in the served counties, geocoded), de-duplicated,
 * population-descending. Each point also carries the town's published page (matched on the shared Census
 * GEOID, never by name) so the report can separate "we rank with a page" from "we rank with nothing" from
 * "no page, no rank" — the last being where to build next.
 *
 * Operator context crosses tenants, so the {@see SiteScope} is dropped and site_id filtered explicitly.
 */
final class TownRankPoints
{
    public function __construct(private readonly CoverageGrid $coverage) {}

    /**
     * @return list<array{coverage_area_id: string, geo_id: string|null, label: string, state: string|null, lat: float, lng: float, population: int, page_slug: string|null, page_url: string|null}>
     */
    public function forSite(Site $site): array
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        $byArea = [];
        foreach ($locations as $location) {
            foreach ($this->coverage->pointsFor($location) as $point) {
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

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('geo_id')
            ->pluck('slug', 'geo_id');

        $domain = is_string($site->domain_url) ? rtrim($site->domain_url, '/') : '';

        $points = [];
        foreach ($byArea as $id => $point) {
            $area = $areas->get($id);
            $geoId = $area !== null && $area->geo_id !== '' ? $area->geo_id : null;
            $state = $area?->state;
            $slug = $geoId !== null && $pages->has($geoId) ? (string) $pages->get($geoId) : null;
            $points[] = [
                'coverage_area_id' => (string) $id,
                'geo_id' => $geoId,
                'label' => $point['label'],
                'state' => $state !== null && $state !== '' ? $state : null,
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'population' => $point['population'],
                'page_slug' => $slug,
                'page_url' => $slug !== null && $domain !== '' ? $domain.'/'.ltrim($slug, '/') : null,
            ];
        }

        usort($points, fn (array $a, array $b): int => $b['population'] <=> $a['population']);

        return $points;
    }
}
