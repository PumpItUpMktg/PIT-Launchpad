<?php

namespace App\Build;

use App\ContentEngine\Reconcile\PostSiloReconciler;
use App\Enums\SpokeTag;
use App\KeywordGenerator\Bucketer;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a site's §4 {@see Silo} rows to the CURRENT spoke tree — removing silos left behind by an
 * earlier structure. {@see GuidedEntityProjector} only ever `firstOrCreate`s silos by name, so a
 * regenerate that renames/drops silos leaves the old rows orphaned; the §4 keyword board then shows
 * silos that aren't in the tree anymore (the "silos for services not present" symptom).
 *
 * A stale silo is one whose name isn't among the current tree's silo names (distinct `Spoke.silo`,
 * fringe excluded). Removal is SAFE and non-destructive to real content: each stale silo's keywords
 * and pages are UNPINNED (`silo_id` → null, they survive), and its BLOG TARGETS are REASSIGNED to a
 * surviving silo (rule_set match on the target's keyword — the same repair {@see PostSiloReconciler}
 * does for posts), preserving status + `article_ref` so a merge never orphans the queue; only a truly
 * unroutable target leaves the lane. THEN the silo is hard-deleted (`Silo` soft-deletes, so a plain
 * delete would linger and could collide with a later same-named silo — a forceDelete leaves the table
 * clean). GUARD: it never deletes when the tree is EMPTY (no spokes) — with no "current" reference that
 * would wipe every silo, so a bare/pre-generate site is left untouched.
 */
class SiloReconciler
{
    public function __construct(private readonly Bucketer $bucketer) {}

    /**
     * Delete §4 silos not present in the current spoke tree.
     *
     * @return array{deleted: int, kept: int, guarded: bool} guarded = true when skipped (empty tree)
     */
    public function reconcile(Site $site): array
    {
        $current = $this->currentSiloNames($site);
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

        if ($current === []) {
            return ['deleted' => 0, 'kept' => $silos->count(), 'guarded' => true];
        }

        $stale = $silos->reject(fn (Silo $s): bool => in_array(mb_strtolower(trim((string) $s->name)), $current, true));
        // The silos that survive this reconcile — the rehoming targets for a stale silo's blog queue.
        $survivors = $silos->reject(fn (Silo $s): bool => $stale->contains('id', $s->id))->values();

        DB::transaction(function () use ($stale, $survivors): void {
            foreach ($stale as $silo) {
                // Unpin real content/keywords (they survive)…
                Keyword::withoutGlobalScope(SiteScope::class)->where('silo_id', $silo->id)->update(['silo_id' => null]);
                Content::withoutGlobalScope(SiteScope::class)->where('silo_id', $silo->id)->update(['silo_id' => null]);
                // …REASSIGN the blog queue to a surviving silo (rule_set match on the keyword), preserving
                // status + article_ref so a merge never orphans the queue; only a truly unroutable target
                // leaves the lane.
                $this->rehomeBlogTargets($silo, $survivors);
                // …then hard-delete so the soft-delete doesn't linger / collide with a re-created name.
                $silo->forceDelete();
            }
        });

        return ['deleted' => $stale->count(), 'kept' => $silos->count() - $stale->count(), 'guarded' => false];
    }

    /**
     * Backfill for queues ALREADY orphaned by an earlier reconcile (before this rehoming existed): any
     * BlogTarget whose `silo_id` no longer points at a live silo is re-filed onto a surviving silo by
     * rule_set match, preserving status + `article_ref`. Idempotent — a no-op once every target is homed.
     *
     * @return array{rehomed: int, dropped: int}
     */
    public function rehomeOrphanedTargets(Site $site): array
    {
        $live = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $liveIds = $live->pluck('id')->all();

        $orphans = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotIn('silo_id', $liveIds === [] ? [''] : $liveIds)
            ->with('keyword')
            ->get();

        $rehomed = 0;
        $dropped = 0;
        DB::transaction(function () use ($orphans, $live, &$rehomed, &$dropped): void {
            foreach ($orphans as $target) {
                $survivor = $this->survivorFor($target, $live);
                if ($survivor !== null) {
                    $target->forceFill(['silo_id' => $survivor->id])->save();
                    $rehomed++;
                } else {
                    $target->delete();
                    $dropped++;
                }
            }
        });

        return ['rehomed' => $rehomed, 'dropped' => $dropped];
    }

    /**
     * Reassign a stale silo's blog queue to a surviving silo (rule_set match on the keyword), preserving
     * status + `article_ref`. A target that can't be homed to any survivor leaves the lane (deleted).
     *
     * @param  Collection<int, Silo>  $survivors
     */
    private function rehomeBlogTargets(Silo $silo, Collection $survivors): void
    {
        $targets = BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('silo_id', $silo->id)->with('keyword')->get();

        foreach ($targets as $target) {
            $survivor = $this->survivorFor($target, $survivors);
            if ($survivor !== null) {
                $target->forceFill(['silo_id' => $survivor->id])->save();
            } else {
                $target->delete();
            }
        }
    }

    /**
     * The surviving silo a blog target should re-home to: the rule_set match for its keyword's query.
     * Consumed targets (drafted/published) rehome exactly like queued ones — they keep their
     * `article_ref` under the surviving silo. Null when nothing matches (truly unroutable).
     *
     * @param  Collection<int, Silo>  $survivors
     */
    private function survivorFor(BlogTarget $target, Collection $survivors): ?Silo
    {
        $query = trim((string) $target->keyword?->query);
        if ($query === '' || $survivors->isEmpty()) {
            return null;
        }

        return $this->bucketer->bucket($query, $survivors);
    }

    /**
     * The stale silo names for a site (no writes) — for a dry-run preview.
     *
     * @return list<string>
     */
    public function stale(Site $site): array
    {
        $current = $this->currentSiloNames($site);
        if ($current === []) {
            return [];
        }

        return Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()
            ->reject(fn (Silo $s): bool => in_array(mb_strtolower(trim((string) $s->name)), $current, true))
            ->map(fn (Silo $s): string => (string) $s->name)
            ->values()
            ->all();
    }

    /**
     * The lowercased silo names of the current spoke tree (empty ⇒ no tree, reconcile is guarded off).
     *
     * @return list<string>
     */
    private function currentSiloNames(Site $site): array
    {
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('tag', '!=', SpokeTag::Fringe->value)
            ->pluck('silo')
            ->map(fn ($name): string => mb_strtolower(trim((string) $name)))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }
}
