<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangementSource;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\DB;

/**
 * The "demote silo to sub-hub under X" operation — applies a {@see SubClusterDetector}
 * recommendation. Distinct from the dissolve-style "fold silo into": demote *preserves*
 * the demoted silo's pillar as a child hub page (`is_sub_hub`, `parent_silo_id`) and keeps
 * its spokes nested beneath it (the stated-service floor holds — nothing is dropped). It
 * then re-runs Pass A so the demoted silo's folded spokes can nest under cores anywhere in
 * the now-expanded parent subtree (e.g. Battery Backup System under Battery Backup Sump Pump).
 *
 * Guards: one level deep only (can't nest under a sub-hub, can't demote a silo that already
 * has sub-hub children), no cycles, no self. Idempotent under the §10 twin: a `Confirmed`
 * demotion is never overwritten by a later `Auto` recommendation.
 */
final class SubHubDemoter
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly FoldTargetAssigner $nesting,
    ) {}

    public function demote(Site $site, string $silo, string $parentSilo, ArrangementSource $source = ArrangementSource::Auto, ?SpokeEmbeddings $vectors = null): bool
    {
        if ($silo === $parentSilo) {
            return false; // no self-parenting
        }

        $pillar = $this->pillar($site, $silo);
        $parent = $this->pillar($site, $parentSilo);

        if ($pillar === null || $parent === null) {
            return false;
        }

        // One-level cap: the target can't already be a sub-hub, and this silo can't already
        // host sub-hubs of its own.
        if ($parent->isSubHub() || $this->hasSubHubChildren($site, $pillar->id)) {
            return false;
        }

        // Cycle guard (belt-and-suspenders given the cap): the target must not nest under us.
        if ($parent->parent_silo_id === $pillar->id) {
            return false;
        }

        // Preservation: an Auto re-run never overwrites an operator-confirmed demotion.
        if ($pillar->isSubHub() && $pillar->arrangement_source === ArrangementSource::Confirmed && $source === ArrangementSource::Auto) {
            return false;
        }

        DB::transaction(function () use ($site, $pillar, $parent, $source, $vectors): void {
            $pillar->update([
                'parent_silo_id' => $parent->id,
                'is_sub_hub' => true,
                'arrangement_source' => $source,
            ]);

            // Re-nest: the demoted silo's folded spokes can now reach the parent subtree's cores.
            $this->nesting->run($site, $vectors ?? new SpokeEmbeddings($this->embeddings));
        });

        return true;
    }

    private function hasSubHubChildren(Site $site, string $pillarId): bool
    {
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('parent_silo_id', $pillarId)
            ->exists();
    }

    private function pillar(Site $site, string $silo): ?Spoke
    {
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('silo', $silo)
            ->where('is_pillar', true)
            ->first();
    }
}
