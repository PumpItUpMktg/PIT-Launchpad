<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\TownName;
use Illuminate\Support\Collection;

/**
 * Derives the census GEOID for each TOWN page from the CURRENT name-match, so the page can be anchored to
 * a stable geo key (`contents.geo_id`) instead of being matched by title forever. This is deriving a CLEAN
 * key from a DIRTY match, so it never guesses: a town page is anchored ONLY when its name resolves to
 * EXACTLY ONE coverage area reachable from the page's serving Location. Everything else — a name that
 * matches several coverage areas (Washington / Springfield / Montgomery across counties), a name whose
 * coverage isn't reachable from the page's parent, or a name that matches no coverage at all — is SURFACED
 * for a human, never anchored.
 *
 * Read-only by default ({@see plan}); {@see execute} writes `geo_id` for the unambiguous set only. Once a
 * page carries a geo_id the whole job→town-page chain is a pure GEOID join
 * (JobCity.place_geoid == contents.geo_id == coverage_areas.geo_id) — no name-match at any hop.
 */
final class TownPageGeoAnchor
{
    /**
     * Classify every town page for a site: anchorable (exactly one reachable coverage area), ambiguous
     * (several), unreachable (name matches coverage the parent doesn't serve), or no_coverage (no match).
     *
     * @return array{
     *   total: int, already: int,
     *   anchorable: list<array{page_id: string, title: string, geo_id: string, coverage: string}>,
     *   ambiguous: list<array{page_id: string, title: string, key: string, candidates: list<array{geo_id: string, name: string}>}>,
     *   unreachable: list<array{page_id: string, title: string, key: string, candidates: list<array{geo_id: string, name: string}>}>,
     *   no_coverage: list<array{page_id: string, title: string, key: string}>,
     * }
     */
    public function plan(Site $site): array
    {
        $coverageByKey = $this->coverageByKey($site);
        $pages = $this->townPages($site);

        $out = ['total' => $pages->count(), 'already' => 0, 'anchorable' => [], 'ambiguous' => [], 'unreachable' => [], 'no_coverage' => []];

        foreach ($pages as $page) {
            if (is_string($page->geo_id) && $page->geo_id !== '') {
                $out['already']++;

                continue;
            }

            $key = TownName::key((string) $page->title);
            $title = (string) $page->title;
            $pageId = (string) $page->id;
            $matches = $coverageByKey[$key] ?? [];

            if ($matches === []) {
                $out['no_coverage'][] = ['page_id' => $pageId, 'title' => $title, 'key' => $key];

                continue;
            }

            // Narrow by the page's serving Location: the parent must reach the coverage area. This is what
            // separates a true "Washington" (one reachable) from the ambiguous case (several reachable).
            $parent = (string) $page->parent_location_id;
            $reachable = array_values(array_filter($matches, fn (array $c): bool => in_array($parent, $c['source_location_ids'], true)));

            $candidates = fn (array $set): array => array_map(fn (array $c): array => ['geo_id' => $c['geo_id'], 'name' => $c['name']], $set);

            match (true) {
                count($reachable) === 1 => $out['anchorable'][] = ['page_id' => $pageId, 'title' => $title, 'geo_id' => $reachable[0]['geo_id'], 'coverage' => $reachable[0]['name']],
                count($reachable) > 1 => $out['ambiguous'][] = ['page_id' => $pageId, 'title' => $title, 'key' => $key, 'candidates' => $candidates($reachable)],
                // Name matches coverage, but none is reachable from the page's parent — a mis-parent/drift
                // signal, not a clean anchor. Surface with the matched-but-unreachable candidates.
                default => $out['unreachable'][] = ['page_id' => $pageId, 'title' => $title, 'key' => $key, 'candidates' => $candidates($matches)],
            };
        }

        return $out;
    }

    /** Write geo_id for the unambiguous set only. Returns the number of pages anchored. */
    public function execute(Site $site): int
    {
        $anchored = 0;
        foreach ($this->plan($site)['anchorable'] as $row) {
            Content::withoutGlobalScope(SiteScope::class)
                ->whereKey($row['page_id'])
                ->update(['geo_id' => $row['geo_id']]);
            $anchored++;
        }

        return $anchored;
    }

    /** @return Collection<int, Content> the site's town pages (town-level location pages) */
    private function townPages(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->whereNull('primary_service_id')
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'parent_location_id', 'geo_id']);
    }

    /**
     * The site's coverage areas grouped by name key — a key can hold SEVERAL areas (same-named towns in
     * different counties), which is exactly the ambiguity the anchor must not resolve by guessing.
     *
     * @return array<string, list<array{geo_id: string, name: string, source_location_ids: list<string>}>>
     */
    private function coverageByKey(Site $site): array
    {
        $map = [];
        $rows = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['geo_id', 'name', 'source_location_ids']);

        foreach ($rows as $area) {
            $key = TownName::key((string) $area->name);
            $map[$key][] = [
                'geo_id' => (string) $area->geo_id,
                'name' => (string) $area->name,
                'source_location_ids' => array_map('strval', (array) $area->source_location_ids),
            ];
        }

        return $map;
    }
}
