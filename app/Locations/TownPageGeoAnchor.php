<?php

namespace App\Locations;

use App\Enums\BuildSource;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\BuildPage;
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
     *   anchorable: list<array{page_id: string, title: string, geo_id: string, coverage: string, via: string}>,
     *   ambiguous: list<array{page_id: string, title: string, key: string, candidates: list<array{geo_id: string, name: string}>}>,
     *   unreachable: list<array{page_id: string, title: string, key: string, candidates: list<array{geo_id: string, name: string}>}>,
     *   no_coverage: list<array{page_id: string, title: string, key: string}>,
     * }
     */
    public function plan(Site $site): array
    {
        $coverageByKey = $this->coverageByKey($site);
        $byPlan = $this->coverageByBuiltPage($site);
        $pages = $this->townPages($site);
        $removedAnchors = $this->removedTwinAnchors($site);

        $out = ['total' => $pages->count(), 'already' => 0, 'anchorable' => [], 'ambiguous' => [], 'unreachable' => [], 'no_coverage' => []];

        foreach ($pages as $page) {
            if (is_string($page->geo_id) && $page->geo_id !== '') {
                $out['already']++;

                continue;
            }

            $key = TownName::key((string) $page->title);
            $title = (string) $page->title;
            $pageId = (string) $page->id;

            // EXACT first: a page the plan built carries its CoverageArea in the manifest entry, so its
            // GEOID is known rather than derived. Nothing here is a guess, so no ambiguity can arise.
            if (isset($byPlan[$pageId])) {
                $out['anchorable'][] = [
                    'page_id' => $pageId, 'title' => $title,
                    'geo_id' => $byPlan[$pageId]['geo_id'], 'coverage' => $byPlan[$pageId]['name'], 'via' => 'plan',
                ];

                continue;
            }

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

            // Several reachable candidates, but a REMOVED numbered twin of this page (the duplicate
            // resolver's loser, soft-deleted) carried one of them: that anchor was real, not a guess —
            // inherit it. Bristol borough vs Bristol Township is undecidable by name; the twin knew.
            $inherited = null;
            if (count($reachable) > 1) {
                $twinGeo = $removedAnchors[$parent.'|'.$key] ?? null;
                foreach ($reachable as $c) {
                    if ($twinGeo !== null && $c['geo_id'] === $twinGeo) {
                        $inherited = $c;
                    }
                }
            }

            match (true) {
                count($reachable) === 1 => $out['anchorable'][] = ['page_id' => $pageId, 'title' => $title, 'geo_id' => $reachable[0]['geo_id'], 'coverage' => $reachable[0]['name'], 'via' => 'name'],
                $inherited !== null => $out['anchorable'][] = ['page_id' => $pageId, 'title' => $title, 'geo_id' => $inherited['geo_id'], 'coverage' => $inherited['name'], 'via' => 'removed twin'],
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

    /**
     * Pin one town page to a GEOID by hand — the way an AMBIGUOUS page (two real towns with one name,
     * borough vs township) is settled. Refuses anything it cannot vouch for: the page must be a town page
     * on this site, and the GEOID a coverage area reachable from the page's serving Location (a wrong pin
     * would title and ground the page on a town the office does not serve).
     *
     * @return array{ok: bool, slug: string, geo_id: string, note: string}
     */
    public function pin(Site $site, string $slug, string $geoId, bool $write): array
    {
        $slug = trim($slug, '/');
        $geoId = trim($geoId);
        $page = $this->townPages($site)->first(fn (Content $c): bool => trim((string) $c->slug, '/') === $slug);
        if ($page === null) {
            return ['ok' => false, 'slug' => $slug, 'geo_id' => $geoId, 'note' => 'no town page with that slug on this site'];
        }
        $area = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('geo_id', $geoId)->first(['geo_id', 'name', 'source_location_ids']);
        if ($area === null) {
            return ['ok' => false, 'slug' => $slug, 'geo_id' => $geoId, 'note' => 'no coverage area with that GEOID on this site'];
        }
        $sources = is_array($area->source_location_ids) ? $area->source_location_ids : [];
        if (! in_array((string) $page->parent_location_id, $sources, true)) {
            return ['ok' => false, 'slug' => $slug, 'geo_id' => $geoId, 'note' => "{$area->name} [{$geoId}] is not reachable from this page's serving location"];
        }
        if ($write) {
            Content::withoutGlobalScope(SiteScope::class)->whereKey($page->id)->update(['geo_id' => $geoId]);
        }

        return ['ok' => true, 'slug' => $slug, 'geo_id' => $geoId, 'note' => ($write ? 'pinned to ' : 'would pin to ').$area->name." [{$geoId}]"];
    }

    /**
     * The geo anchors of soft-deleted town pages (the duplicate resolver's losers), keyed by
     * "{parent location}|{town key}" — so a surviving twin can inherit what its removed copy knew.
     *
     * @return array<string, string>
     */
    private function removedTwinAnchors(Site $site): array
    {
        $out = [];
        $rows = Content::withoutGlobalScope(SiteScope::class)->onlyTrashed()
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->whereNotNull('geo_id')
            ->get(['title', 'parent_location_id', 'geo_id']);
        foreach ($rows as $row) {
            $out[(string) $row->parent_location_id.'|'.TownName::key((string) $row->title)] = (string) $row->geo_id;
        }

        return $out;
    }

    /**
     * Pages the build plan created, mapped to the coverage area their manifest entry names. A location
     * entry's `page_key` IS the CoverageArea id — the exact link, kept from when the page was planned.
     *
     * @return array<string, array{geo_id: string, name: string}>
     */
    private function coverageByBuiltPage(Site $site): array
    {
        $entries = BuildPage::query()
            ->where('site_id', $site->id)
            ->where('source', BuildSource::Location)
            ->whereNotNull('content_id')
            ->get(['content_id', 'page_key']);
        if ($entries->isEmpty()) {
            return [];
        }

        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereKey($entries->pluck('page_key')->all())
            ->get(['id', 'geo_id', 'name'])
            ->keyBy(fn (CoverageArea $a): string => (string) $a->id);

        $out = [];
        foreach ($entries as $entry) {
            $area = $areas->get((string) $entry->page_key);
            if ($area !== null && trim((string) $area->geo_id) !== '') {
                $out[(string) $entry->content_id] = ['geo_id' => (string) $area->geo_id, 'name' => (string) $area->name];
            }
        }

        return $out;
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
