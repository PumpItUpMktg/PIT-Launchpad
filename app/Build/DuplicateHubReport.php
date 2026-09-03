<?php

namespace App\Build;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * READ-ONLY verification of duplicate "hub" pages — the "report before deleting" pass for the hub cleanup.
 * It changes nothing; it only surfaces what a cleanup WOULD touch, so a destructive sweep is proposed and
 * reviewed with real counts before any row is removed.
 *
 * "Hub" is overloaded in this codebase, so this reports THREE hub-shaped duplications so a clean result is
 * airtight rather than a false negative from too narrow a definition:
 *
 *   1. SILO HUBS — `page_type=Hub` pinned to a `silo_id`. The system assumes one per silo (nesting keys hubs
 *      by `silo_id`) but nothing enforces it; a structure rebuild can mint a second. Grouped by `silo_id`.
 *   2. ORPHAN HUBS — `page_type=Hub` with a NULL `silo_id` (a hub that lost its silo pin, invisible to the
 *      silo-keyed pass). Grouped by normalized title so same-town/name orphans still surface.
 *   3. LOCATION LANDINGS — the OTHER "hub": a `page_type=Location` page WITH a `location_id` (the physical GBP
 *      location's landing page, which the town-page code literally calls "the hub"). Grouped by `location_id`.
 *      (Town pages — `page_type=Location`, NULL `location_id`, with a `parent_location_id` — are NOT landings
 *      and are handled by {@see DuplicateTownSweeper}; they are excluded here.)
 *
 * For every duplicated group it names the KEEPER (furthest-along then oldest, identical to the town sweeper),
 * splits the rest into REMOVABLE (empty + unpublished) vs BLOCKED (published or drafted — manual only), and
 * counts CHILDREN parented to a non-keeper via `parent_content_id` (which a cleanup must re-point, since that
 * column has no FK cascade). Nothing is mutated.
 *
 * @phpstan-type HubGroup array{key: string, label: string, total: int, keeper: array{id: string, slug: string, status: string}, removable: list<array{id: string, slug: string, status: string}>, blocked: list<array{id: string, slug: string, status: string}>, children_to_repoint: int}
 */
class DuplicateHubReport
{
    /**
     * Duplicate silo hubs for one site (silos carrying more than one `page_type=Hub` page).
     *
     * @return list<HubGroup>
     */
    public function forSite(Site $site): array
    {
        $hubs = $this->pages($site, PageType::Hub)->whereNotNull('silo_id');
        $names = Silo::withoutGlobalScopes()
            ->whereIn('id', $hubs->pluck('silo_id')->unique()->all())
            ->pluck('name', 'id');

        return $this->groups(
            $site,
            $hubs,
            fn (Content $c): string => (string) $c->silo_id,
            fn (string $key): string => (string) ($names[$key] ?? '—'),
        );
    }

    /**
     * Orphan hubs — `page_type=Hub` with no `silo_id` — grouped by normalized title so same-name dupes surface.
     *
     * @return list<HubGroup>
     */
    public function orphanHubs(Site $site): array
    {
        $hubs = $this->pages($site, PageType::Hub)->whereNull('silo_id');

        return $this->groups(
            $site,
            $hubs,
            fn (Content $c): string => $this->titleKey((string) $c->title),
            fn (string $key, Collection $g): string => (string) ($g->first()->title ?? $key),
        );
    }

    /**
     * Duplicate location landings — `page_type=Location` WITH a `location_id` — grouped by `location_id`.
     *
     * @return list<HubGroup>
     */
    public function locationLandings(Site $site): array
    {
        $landings = $this->pages($site, PageType::Location)->whereNotNull('location_id');
        $names = Location::withoutGlobalScopes()
            ->whereIn('id', $landings->pluck('location_id')->unique()->all())
            ->pluck('name', 'id');

        return $this->groups(
            $site,
            $landings,
            fn (Content $c): string => (string) $c->location_id,
            fn (string $key): string => (string) ($names[$key] ?? '—'),
        );
    }

    /**
     * Every site with at least one duplicated hub in ANY of the three categories, keyed by site id.
     *
     * @return array<string, array{site: Site, silo_hubs: list<HubGroup>, orphan_hubs: list<HubGroup>, location_landings: list<HubGroup>}>
     */
    public function report(): array
    {
        $out = [];
        foreach (Site::withoutGlobalScopes()->get() as $site) {
            $silo = $this->forSite($site);
            $orphan = $this->orphanHubs($site);
            $landings = $this->locationLandings($site);
            if ($silo !== [] || $orphan !== [] || $landings !== []) {
                $out[(string) $site->id] = [
                    'site' => $site,
                    'silo_hubs' => $silo,
                    'orphan_hubs' => $orphan,
                    'location_landings' => $landings,
                ];
            }
        }

        return $out;
    }

    /**
     * All Content pages of one page_type for a site, scope-free for determinism.
     *
     * @return Collection<int, Content>
     */
    private function pages(Site $site, PageType $type): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', $type->value)
            ->get();
    }

    /**
     * The shared grouping core: group `$rows` by `$groupKey`, keep only groups with more than one row, and
     * for each emit keeper / removable / blocked / children-to-repoint. `$labelFor` receives the key (and the
     * group) and returns a human label.
     *
     * @param  Collection<int, Content>  $rows
     * @param  callable(Content): string  $groupKey
     * @param  callable(string, Collection<int, Content>): string  $labelFor
     * @return list<HubGroup>
     */
    private function groups(Site $site, Collection $rows, callable $groupKey, callable $labelFor): array
    {
        $groups = $rows->groupBy($groupKey)->filter(fn (Collection $g): bool => $g->count() > 1);
        if ($groups->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($groups as $key => $group) {
            $keeper = $this->canonical($group);

            $removable = [];
            $blocked = [];
            $nonKeeperIds = [];
            foreach ($group as $page) {
                if ((string) $page->id === (string) $keeper->id) {
                    continue;
                }
                $nonKeeperIds[] = (string) $page->id;
                $row = ['id' => (string) $page->id, 'slug' => (string) $page->slug, 'status' => $page->status->value];
                if ($this->isRemovable($page)) {
                    $removable[] = $row;
                } else {
                    $blocked[] = $row;
                }
            }

            $childrenToRepoint = $nonKeeperIds === [] ? 0 : Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereIn('parent_content_id', $nonKeeperIds)
                ->count();

            $out[] = [
                'key' => (string) $key,
                'label' => $labelFor((string) $key, $group),
                'total' => $group->count(),
                'keeper' => ['id' => (string) $keeper->id, 'slug' => (string) $keeper->slug, 'status' => $keeper->status->value],
                'removable' => $removable,
                'blocked' => $blocked,
                'children_to_repoint' => $childrenToRepoint,
            ];
        }

        return $out;
    }

    /**
     * The keeper for a group: furthest-along then oldest (published > drafted > earliest) — identical to
     * {@see DuplicateTownSweeper::canonical()} so the report matches what a sweep would keep.
     *
     * @param  Collection<int, Content>  $group
     */
    private function canonical(Collection $group): Content
    {
        return $group->sortByDesc(fn (Content $c): array => [
            $c->status === ContentStatus::Published ? 1 : 0,
            $c->hasDraft() ? 1 : 0,
            -($c->created_at->timestamp),
        ])->first();
    }

    /** Removable only when it is an EMPTY extra: no real draft and not published/live (mirrors the town sweeper). */
    private function isRemovable(Content $page): bool
    {
        return ! $page->hasDraft()
            && $page->status !== ContentStatus::Published
            && $page->wp_post_id === null;
    }

    /** Normalize a title for matching orphan hubs (drop a trailing ", ST", lower) — mirrors the town key. */
    private function titleKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
