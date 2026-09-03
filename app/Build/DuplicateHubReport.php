<?php

namespace App\Build;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * READ-ONLY verification of duplicate silo-hub pages — the "report before deleting" pass for the hub
 * cleanup (the hub analogue of {@see DuplicateTownSweeper}, which this NEVER mutates for). It changes
 * nothing; it only surfaces what a cleanup WOULD touch, so a destructive sweep is proposed and reviewed
 * with real counts before any row is removed.
 *
 * A silo hub is a `Content` `kind=page`, `page_type=Hub` pinned to a `silo_id`. The system assumes ONE hub
 * per silo (nesting keys hubs by `silo_id`), but nothing enforces it, and a structure rebuild can mint a
 * second one (new spoke ids → new BuildPage rows with `content_id=null` → an unguarded `Content::create`).
 * The natural dedupe key is therefore `(site_id, silo_id)` restricted to Hub pages; a hub with a NULL
 * `silo_id` has no group and is ignored.
 *
 * For each duplicated silo it names the KEEPER (the same furthest-along-then-oldest canonical the town
 * sweeper uses), splits the rest into REMOVABLE (empty, unpublished extras a sweep could safely soft-delete)
 * vs BLOCKED (published or carrying a real draft — never auto-removable), and counts the CHILDREN currently
 * parented to a non-keeper hub via `parent_content_id`, which a cleanup would have to re-point to the keeper
 * (the child-orphaning risk that makes hubs different from towns).
 */
class DuplicateHubReport
{
    /**
     * Duplicate-hub groups for one site (silos carrying more than one Hub page). Empty when the site is clean.
     *
     * @return list<array{silo_id: string, silo_name: string, total: int, keeper: array{id: string, slug: string, status: string}, removable: list<array{id: string, slug: string, status: string}>, blocked: list<array{id: string, slug: string, status: string}>, children_to_repoint: int}>
     */
    public function forSite(Site $site): array
    {
        $hubs = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Hub->value)
            ->whereNotNull('silo_id')
            ->get();

        $groups = $hubs
            ->groupBy(fn (Content $c): string => (string) $c->silo_id)
            ->filter(fn (Collection $g): bool => $g->count() > 1);

        if ($groups->isEmpty()) {
            return [];
        }

        $siloNames = Silo::withoutGlobalScopes()->whereIn('id', $groups->keys())->pluck('name', 'id');

        $out = [];
        foreach ($groups as $siloId => $group) {
            $keeper = $this->canonical($group);

            $removable = [];
            $blocked = [];
            $nonKeeperIds = [];
            foreach ($group as $hub) {
                if ((string) $hub->id === (string) $keeper->id) {
                    continue;
                }
                $nonKeeperIds[] = (string) $hub->id;
                $row = ['id' => (string) $hub->id, 'slug' => (string) $hub->slug, 'status' => $hub->status->value];
                if ($this->isRemovable($hub)) {
                    $removable[] = $row;
                } else {
                    $blocked[] = $row; // published or drafted-in-review — a sweep must leave these for manual review
                }
            }

            // Children currently nested under a NON-keeper hub — a cleanup must re-point their
            // parent_content_id to the keeper (no FK cascade), else they'd be orphaned.
            $childrenToRepoint = $nonKeeperIds === [] ? 0 : Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereIn('parent_content_id', $nonKeeperIds)
                ->count();

            $out[] = [
                'silo_id' => (string) $siloId,
                'silo_name' => (string) ($siloNames[$siloId] ?? '—'),
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
     * Every site with at least one duplicated silo hub, keyed by site id.
     *
     * @return array<string, array{site: Site, groups: list<array<string, mixed>>}>
     */
    public function report(): array
    {
        $out = [];
        foreach (Site::withoutGlobalScopes()->get() as $site) {
            $groups = $this->forSite($site);
            if ($groups !== []) {
                $out[(string) $site->id] = ['site' => $site, 'groups' => $groups];
            }
        }

        return $out;
    }

    /**
     * The keeper for a same-silo hub group: furthest-along then oldest (published > drafted > earliest) —
     * identical to {@see DuplicateTownSweeper::canonical()} so the report matches what a sweep would keep.
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
    private function isRemovable(Content $hub): bool
    {
        return ! $hub->hasDraft()
            && $hub->status !== ContentStatus::Published
            && $hub->wp_post_id === null;
    }
}
