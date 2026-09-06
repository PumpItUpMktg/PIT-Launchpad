<?php

namespace App\Operator\Coverage;

use App\Build\DuplicateTownSweeper;
use App\Console\Commands\MergeMarketsCommand;
use App\Enums\ContentStatus;
use App\Locations\CoverageName;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PageIndexState;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Merges a DUPLICATE market into its clean twin and deletes the duplicate.
 *
 * Two market rows that share a `geo_id` (a Census place code) are the SAME place — a duplicate, whatever
 * their names ("1, Abingdon" over an existing "Abingdon"). `geo_id` is the authoritative signal: the name
 * check can't see it (the renamer's collision flag only knows two rows would COLLIDE, not that they are the
 * same place). So this compares on `geo_id` — the primary key for "is this the same market".
 *
 * Disposition, per duplicate group of one CLEAN-named survivor + one or more numbered duplicates:
 *   - reassign every dependent of the duplicate to the survivor — keywords, content, position snapshots,
 *     geo prompts (direct market_id), and the service / proof / media pivots (dedupe-safe: the survivor
 *     inherits the union, the duplicate's rows drop on delete);
 *   - delete the duplicate market;
 *   - CLEAN the place's `CoverageArea` name if it is still numbered — there is exactly ONE CoverageArea per
 *     (site, geo_id) (a unique index), so if it still reads "1, Abingdon" the next build's `projectTerritories`
 *     firstOrCreate's THAT and re-mints the duplicate. Aligning it to the survivor's clean name (via the one
 *     canonical {@see CoverageName::clean()}) closes that; it is the town's only CoverageArea, so it is
 *     cleaned in place, never deleted.
 *
 * Only the unambiguous case auto-merges: exactly one clean-named survivor + ≥1 dirty duplicate on a geo_id.
 * A group that is all-clean, all-dirty, or many-to-many is flagged AMBIGUOUS and left for a human — no guess
 * about which row survives. Report-only by planning; {@see MergeMarketsCommand} is report-only by default.
 *
 * PAGE-COLLISION GUARD — both markets can hold a page for the SAME town (the loser is a duplicate market, so
 * it accumulated its own copy of that town's pages). A blind reassign would leave the survivor with TWO pages
 * for one town — the very Buckingham-style duplicate this whole line of work is retiring, self-inflicted. So a
 * loser page whose (page_type, service, cleaned town key) matches a survivor page is NOT reassigned:
 *   - an EMPTY extra (unpublished, undrafted, AND never pushed to WP — no wp_post_id, so no live URL) is
 *     soft-deleted — the survivor keeps its canonical page (mirrors {@see DuplicateTownSweeper}'s rule);
 *   - a PUBLISHED, drafted, or already-pushed-to-WP collision is a live/real page whose take-down is a human
 *     decision, so its presence flags the whole group COLLISION and refuses the merge (reported, never
 *     auto-removed). The collision report shows the index verdict of BOTH pages, because the survivor was
 *     chosen by name cleanliness, not by which page ranks — the operator must see what would be dropped.
 * The loser's non-colliding pages reassign as before (they are the survivor's only page for those towns).
 */
final class MarketMerger
{
    /**
     * @return list<array{
     *   geo_id: string, ambiguous: bool, collision: bool, names: list<string>,
     *   winner_id: ?string, winner_name: ?string, loser_id: ?string, loser_name: ?string,
     *   area_id: ?string, area_dirty: bool,
     *   colliding_page_ids: list<string>, page_collisions: int,
     *   soft_collisions: list<array{loser_id:string,title:string,loser_index:string,winner_index:string}>,
     *   hard_collisions: list<array{loser_id:string,winner_id:string,title:string,reason:string,loser_index:string,winner_index:string}>,
     *   dependents: array{keywords:int,content:int,snapshots:int,geo_prompts:int,services:int,proof:int,media:int}
     * }>
     */
    public function plan(Site $site): array
    {
        $markets = Market::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->whereNotNull('geo_id')->where('geo_id', '!=', '')
            ->get();

        $rows = [];
        foreach ($markets->groupBy('geo_id') as $geoId => $group) {
            if ($group->count() < 2) {
                continue; // no twin — not a duplicate
            }

            $clean = $group->filter(fn (Market $m): bool => ! CoverageName::isDirty((string) $m->name))->values();
            $dirty = $group->filter(fn (Market $m): bool => CoverageName::isDirty((string) $m->name))->values();

            // Auto-merge ONLY the clear case: one clean survivor + one-or-more numbered duplicates.
            if ($clean->count() !== 1 || $dirty->isEmpty()) {
                $rows[] = [
                    'geo_id' => (string) $geoId, 'ambiguous' => true, 'collision' => false,
                    'names' => $group->map(fn (Market $m): string => (string) $m->name)->all(),
                    'winner_id' => null, 'winner_name' => null, 'loser_id' => null, 'loser_name' => null,
                    'area_id' => null, 'area_dirty' => false,
                    'colliding_page_ids' => [], 'page_collisions' => 0,
                    'soft_collisions' => [], 'hard_collisions' => [],
                    'dependents' => $this->zeroDeps(),
                ];

                continue;
            }

            [$areaId, $areaDirty] = $this->coverageAreaFor($site, (string) $geoId);
            $winner = $clean->first();
            foreach ($dirty as $loser) {
                $collisions = $this->pageCollisions($site, (string) $winner->id, (string) $loser->id);
                $rows[] = [
                    'geo_id' => (string) $geoId, 'ambiguous' => false, 'collision' => $collisions['hard'] !== [],
                    'names' => $group->map(fn (Market $m): string => (string) $m->name)->all(),
                    'winner_id' => (string) $winner->id, 'winner_name' => (string) $winner->name,
                    'loser_id' => (string) $loser->id, 'loser_name' => (string) $loser->name,
                    'area_id' => $areaId, 'area_dirty' => $areaDirty,
                    'colliding_page_ids' => array_map(fn (array $s): string => $s['loser_id'], $collisions['soft']),
                    'page_collisions' => count($collisions['soft']) + count($collisions['hard']),
                    'soft_collisions' => $collisions['soft'],
                    'hard_collisions' => $collisions['hard'],
                    'dependents' => $this->deps($site, (string) $loser->id),
                ];
            }
        }

        return $rows;
    }

    /** Apply every unambiguous, collision-free merge. Returns the number of duplicate markets merged + deleted. */
    public function apply(Site $site): int
    {
        $plan = array_values(array_filter($this->plan($site), fn (array $r): bool => ! $r['ambiguous'] && ! $r['collision']));

        foreach ($plan as $r) {
            /** @var string $winnerId */
            $winnerId = $r['winner_id'];
            /** @var string $loserId */
            $loserId = $r['loser_id'];

            DB::transaction(function () use ($site, $winnerId, $loserId, $r): void {
                $winner = Market::withoutGlobalScopes()->findOrFail($winnerId);
                $loser = Market::withoutGlobalScopes()->findOrFail($loserId);

                // Pivots — the survivor inherits the duplicate's relations (dedupe-safe); the duplicate's own
                // rows drop when the market is deleted (cascadeOnDelete).
                $winner->services()->syncWithoutDetaching($loser->services()->pluck('services.id')->all());
                $winner->proofItems()->syncWithoutDetaching($loser->proofItems()->pluck('proof_items.id')->all());
                $winner->mediaAssets()->syncWithoutDetaching($loser->mediaAssets()->pluck('media_assets.id')->all());

                // Direct market_id FKs — reassign to the survivor.
                Keyword::withoutGlobalScopes()->where('market_id', $loserId)->update(['market_id' => $winnerId]);
                PositionSnapshot::query()->where('market_id', $loserId)->update(['market_id' => $winnerId]);
                DB::table('geo_prompts')->where('market_id', $loserId)->update(['market_id' => $winnerId]);

                // Content — reassign every loser page EXCEPT the ones that collide with a survivor page for the
                // same town; reassigning those would give the survivor two pages for one town. The colliding
                // pages here are all EMPTY extras (a published/drafted collision would have refused the merge),
                // so they are soft-deleted, and their plan rows dropped so a materialize can't resurrect them.
                $collidingIds = $r['colliding_page_ids'];
                Content::withoutGlobalScopes()->where('market_id', $loserId)
                    ->when($collidingIds !== [], fn ($q) => $q->whereNotIn('id', $collidingIds))
                    ->update(['market_id' => $winnerId]);
                if ($collidingIds !== []) {
                    BuildPage::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereIn('content_id', $collidingIds)->delete();
                    Content::withoutGlobalScopes()->whereIn('id', $collidingIds)->delete();
                }

                // Delete the duplicate market. Then, if the place's ONE CoverageArea (unique per (site, geo_id))
                // is still numbered, clean it to the survivor's name so the next build's projectTerritories
                // firstOrCreate's the survivor by name and re-mints nothing. It is the town's only area — cleaned
                // in place, never deleted.
                Market::withoutGlobalScopes()->whereKey($loserId)->delete();
                if ($r['area_id'] !== null && $r['area_dirty']) {
                    $area = CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->find($r['area_id']);
                    if ($area !== null) {
                        $clean = CoverageName::clean((string) $area->getAttribute('name'));
                        CoverageArea::withoutGlobalScopes()->whereKey($r['area_id'])->update(['name' => $clean]);
                    }
                }
            });
        }

        return count($plan);
    }

    /**
     * The place's single CoverageArea (unique per (site, geo_id)) and whether its name is still numbered.
     * A dirty area is the re-mint risk: the next build firstOrCreate's the market from THIS name, so if it
     * still reads "1, Abingdon" the merge must clean it. Returns [id|null, dirty].
     *
     * @return array{0: ?string, 1: bool}
     */
    private function coverageAreaFor(Site $site, string $geoId): array
    {
        $area = CoverageArea::withoutGlobalScopes()->where('site_id', $site->id)->where('geo_id', $geoId)->first();
        if ($area === null) {
            return [null, false];
        }

        return [(string) $area->id, CoverageName::isDirty((string) $area->getAttribute('name'))];
    }

    /**
     * Loser pages that would collide with a survivor page for the SAME town — the self-inflicted-duplicate
     * risk when both markets hold that town's page. Identity is (page_type, primary_service, town key of the
     * CLEANED title): the loser's title is dirty ("1, Abingdon, MD"), the survivor's clean ("Abingdon, MD") —
     * the same town once the numbered artifact is stripped, so both are normalized before comparison.
     *
     * A colliding loser page is SOFT — safe to soft-delete, keeping the survivor's canonical page — ONLY when
     * it is a truly empty extra: undrafted, unpublished, AND never pushed to WP (`wp_post_id === null`, so it
     * has no live URL). Anything else is HARD: a live/real page whose take-down is a human call, so it refuses
     * the whole merge. The wp_post_id check matters on its own — an "unpublished, undrafted" row can still carry
     * a wp_post_id from an earlier push, and soft-deleting that would orphan a live URL (the row leaves the
     * panel while the page keeps serving).
     *
     * Each entry carries the index verdict of BOTH the loser and the surviving page: "winner" was chosen by
     * name cleanliness, not by which page ranks, so the operator must see whether discarding the loser drops an
     * indexed page in favour of an un-indexed one.
     *
     * @return array{
     *   soft: list<array{loser_id:string,title:string,loser_index:string,winner_index:string}>,
     *   hard: list<array{loser_id:string,winner_id:string,title:string,reason:string,loser_index:string,winner_index:string}>
     * }
     */
    private function pageCollisions(Site $site, string $winnerId, string $loserId): array
    {
        /** @var array<string, Content> $winnerByKey */
        $winnerByKey = [];
        foreach (Content::withoutGlobalScopes()->where('site_id', $site->id)->where('market_id', $winnerId)->get() as $wp) {
            $winnerByKey[$this->pageKey($wp)] ??= $wp;
        }

        $soft = [];
        $hard = [];
        foreach (Content::withoutGlobalScopes()->where('site_id', $site->id)->where('market_id', $loserId)->get() as $page) {
            $match = $winnerByKey[$this->pageKey($page)] ?? null;
            if ($match === null) {
                continue;
            }

            $title = (string) $page->title;
            $loserIndex = $this->indexVerdict((string) $page->id);
            $winnerIndex = $this->indexVerdict((string) $match->id);

            if (! $page->hasDraft() && $page->status !== ContentStatus::Published && $page->wp_post_id === null) {
                $soft[] = ['loser_id' => (string) $page->id, 'title' => $title, 'loser_index' => $loserIndex, 'winner_index' => $winnerIndex];
            } else {
                $reason = $page->status === ContentStatus::Published
                    ? 'published'
                    : ($page->wp_post_id !== null ? 'pushed to WP (live URL)' : 'drafted');
                $hard[] = ['loser_id' => (string) $page->id, 'winner_id' => (string) $match->id, 'title' => $title, 'reason' => $reason, 'loser_index' => $loserIndex, 'winner_index' => $winnerIndex];
            }
        }

        return ['soft' => $soft, 'hard' => $hard];
    }

    /** Three-state index verdict from the durable table (mirrors the Live board + duplicate-town report). */
    private function indexVerdict(string $contentId): string
    {
        $row = PageIndexState::withoutGlobalScope(SiteScope::class)->where('content_id', $contentId)->first();
        if ($row === null) {
            return 'not checked';
        }

        return $row->isIndexed()
            ? 'indexed'
            : ($row->coverage_state !== null && $row->coverage_state !== '' ? "not indexed ({$row->coverage_state})" : 'not indexed');
    }

    /** The town-page identity used to spot a survivor/loser collision: type + service + cleaned town key. */
    private function pageKey(Content $page): string
    {
        return $page->page_type->value.'|'
            .($page->primary_service_id ?? '∅').'|'
            .$this->townKey(CoverageName::clean((string) $page->title));
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors the DuplicateTownSweeper. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }

    /** @return array{keywords:int,content:int,snapshots:int,geo_prompts:int,services:int,proof:int,media:int} */
    private function deps(Site $site, string $marketId): array
    {
        return [
            'keywords' => Keyword::withoutGlobalScopes()->where('market_id', $marketId)->count(),
            'content' => Content::withoutGlobalScopes()->where('market_id', $marketId)->count(),
            'snapshots' => PositionSnapshot::query()->where('market_id', $marketId)->count(),
            'geo_prompts' => DB::table('geo_prompts')->where('market_id', $marketId)->count(),
            'services' => DB::table('market_service')->where('market_id', $marketId)->count(),
            'proof' => DB::table('proof_item_market')->where('market_id', $marketId)->count(),
            'media' => DB::table('media_asset_market')->where('market_id', $marketId)->count(),
        ];
    }

    /** @return array{keywords:int,content:int,snapshots:int,geo_prompts:int,services:int,proof:int,media:int} */
    private function zeroDeps(): array
    {
        return ['keywords' => 0, 'content' => 0, 'snapshots' => 0, 'geo_prompts' => 0, 'services' => 0, 'proof' => 0, 'media' => 0];
    }
}
