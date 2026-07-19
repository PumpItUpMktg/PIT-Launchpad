<?php

namespace App\Build;

use App\Enums\SpokeTag;
use App\Models\BlogTarget;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a site's §4 {@see Silo} rows to the CURRENT spoke tree — removing silos left behind by an
 * earlier structure. {@see GuidedEntityProjector} only ever `firstOrCreate`s silos by name, so a
 * regenerate that renames/drops silos leaves the old rows orphaned; the §4 keyword board then shows
 * silos that aren't in the tree anymore (the "silos for services not present" symptom).
 *
 * A stale silo is one whose name isn't among the current tree's silo names (distinct `Spoke.silo`,
 * fringe excluded). Removal is SAFE and non-destructive to real content: each stale silo's keywords
 * and pages are UNPINNED (`silo_id` → null, they survive) and its stale blog targets dropped, THEN the
 * silo is hard-deleted (`Silo` soft-deletes, so a plain delete would linger and could collide with a
 * later same-named silo — a forceDelete leaves the table clean). GUARD: it never deletes when the tree
 * is EMPTY (no spokes) — with no "current" reference that would wipe every silo, so a bare/pre-generate
 * site is left untouched.
 */
class SiloReconciler
{
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

        DB::transaction(function () use ($stale): void {
            foreach ($stale as $silo) {
                // Unpin real content/keywords (they survive), drop only the stale queued targets…
                Keyword::withoutGlobalScope(SiteScope::class)->where('silo_id', $silo->id)->update(['silo_id' => null]);
                Content::withoutGlobalScope(SiteScope::class)->where('silo_id', $silo->id)->update(['silo_id' => null]);
                BlogTarget::withoutGlobalScope(SiteScope::class)->where('silo_id', $silo->id)->delete();
                // …then hard-delete so the soft-delete doesn't linger / collide with a re-created name.
                $silo->forceDelete();
            }
        });

        return ['deleted' => $stale->count(), 'kept' => $silos->count() - $stale->count(), 'guarded' => false];
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
