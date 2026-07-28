<?php

namespace App\Build;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Collapse DUPLICATE town pages down to one per town — the self-healing sweep that runs as part of
 * {@see PlanSync} (Sync plan) so the operator never has to reach for the CLI. It targets the exact
 * shape that minted bridgewater-nj-3 / bristol-pa-6: several Content rows for the SAME physical town,
 * left over from a pre-dedupe materialize (before {@see BuildManifestAssembler} deduped by town).
 *
 * A town page is a `page` with `page_type=Location`, no `location_id` (that's the hub), no
 * `primary_service_id`, and a `parent_location_id`. Two town pages are the SAME town when they share
 * `(parent_location_id, townKey(title))` — the same normalization the Operate directory and the CLI
 * dedupe use.
 *
 * CONSERVATIVE BY DESIGN — this runs unattended, so it only ever removes an *empty* extra:
 *   - Per town group it keeps ONE canonical (furthest-along, then oldest: published > drafted > earliest).
 *   - It soft-deletes only the non-canonical rows that are UNDRAFTED and NOT published (the
 *     "ready to generate / draft-failed" extras) + drops their BuildPage plan row so a later
 *     materialize can't re-create them.
 *   - It NEVER touches a published/live page or one carrying a real draft (in review) — those are
 *     left for the explicit `launchpad:dedupe-town-pages` path. No drafted work is ever destroyed.
 */
class DuplicateTownSweeper
{
    /** @return int the number of duplicate (undrafted, unpublished) town pages soft-deleted */
    public function sweep(Site $site): int
    {
        $townPages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNull('primary_service_id')
            ->whereNotNull('parent_location_id')
            ->get();

        // Group by the physical town: parent GBP location + normalized town name.
        $groups = $townPages->groupBy(
            fn (Content $c): string => $c->parent_location_id.'|'.$this->townKey((string) $c->title)
        );

        $removeIds = [];
        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue; // one page for this town — nothing to sweep
            }

            $canonical = $this->canonical($group);
            foreach ($group as $page) {
                if ((string) $page->id === (string) $canonical->id) {
                    continue; // keep the canonical
                }
                if ($this->isRemovable($page)) {
                    $removeIds[] = (string) $page->id;
                }
                // A non-canonical page that is published or drafted-in-review is LEFT in place
                // (never auto-removed) — the explicit CLI dedupe resolves those.
            }
        }

        if ($removeIds === []) {
            return 0;
        }

        // Drop the plan rows first so a later materialize can't resurrect the removed pages, then
        // soft-delete the Content (recoverable).
        BuildPage::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('content_id', $removeIds)
            ->delete();

        Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $removeIds)
            ->delete();

        return count($removeIds);
    }

    /**
     * The keeper for a same-town group: furthest-along then oldest — a live page always wins (never
     * deleted), then a real draft, then the earliest-created.
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

    /** Removable only when it is an EMPTY extra: no real draft and not published/live. */
    private function isRemovable(Content $page): bool
    {
        return ! $page->hasDraft()
            && $page->status !== ContentStatus::Published
            && $page->wp_post_id === null;
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors the CLI dedupe. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
