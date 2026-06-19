<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\SpokeGranularity;
use App\Interview\Prune\PrunePlan;
use App\Interview\Prune\PruneRow;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * Pass E — floor & dead-silo reconciliation. After the structural moves it enforces the
 * invariants so auto-arrange never silently drops a stated service or leaves a dangling
 * section:
 *
 *   - Floor: every folded spoke has a valid home. A fold target must be an own-page core
 *     or a pillar/sub-hub — never another folded page; if one became invalid (e.g. its
 *     target was itself folded by a later pass) its children are re-pointed to the silo
 *     pillar. A null target is filled with the pillar. A stated service always lands as a
 *     section, never orphaned.
 *   - Dead-silo advisory: re-emits the §4/prune dead-silo flag (no non-pillar core clears
 *     the own-page bar AND the silo's total volume is below it) — advisory only, the
 *     operator confirms the fold.
 *
 * Only arrangeable spokes are re-pointed; operator-confirmed structure is preserved.
 */
final class FloorReconciler
{
    public function run(Site $site, SpokeEmbeddings $vectors): ArrangeResult
    {
        $spokes = $this->spokes($site);
        $byId = $spokes->keyBy('id');
        $pillarsBySilo = $spokes->filter(fn (Spoke $s) => $s->is_pillar)->keyBy(fn (Spoke $s) => (string) $s->silo);

        $reconciled = 0;

        foreach ($spokes as $spoke) {
            if ($spoke->is_pillar || $spoke->granularity !== SpokeGranularity::Folded || ! $spoke->isArrangeable()) {
                continue;
            }

            $pillar = $pillarsBySilo->get((string) $spoke->silo);
            $target = $spoke->fold_into_id !== null ? $byId->get($spoke->fold_into_id) : null;

            if (! $this->validTarget($target)) {
                $spoke->update(['fold_into_id' => $pillar?->id]);
                $reconciled++;
            }
        }

        return new ArrangeResult(['reconciled' => $reconciled], $this->deadSiloFlags($site, $spokes, $pillarsBySilo));
    }

    /** A fold target must be an own-page core or a pillar/sub-hub — never another folded page. */
    private function validTarget(?Spoke $target): bool
    {
        if (! $target instanceof Spoke) {
            return false;
        }

        return $target->is_pillar || $target->granularity === SpokeGranularity::OwnPage;
    }

    /**
     * @param  Collection<int, Spoke>  $spokes
     * @param  Collection<string, Spoke>  $pillarsBySilo
     * @return list<ArrangeFlag>
     */
    private function deadSiloFlags(Site $site, Collection $spokes, Collection $pillarsBySilo): array
    {
        $plan = new PrunePlan(
            $spokes->map(fn (Spoke $s) => PruneRow::fromSpoke($s))->all(),
            false,
            $site->ownPageBar(),
        );

        $flags = [];
        foreach ($plan->deadSilos() as $silo) {
            $pillar = $pillarsBySilo->get($silo);
            // Skip a silo with no pillar, or one already placed as a sub-hub (its spokes nest
            // into the parent on purpose — it isn't a fold candidate).
            if (! $pillar instanceof Spoke || $pillar->isSubHub()) {
                continue;
            }
            $flags[] = new ArrangeFlag(
                ArrangeFlagType::DeadSilo,
                $pillar->id,
                "\"{$silo}\" has no core clearing the own-page bar and thin total volume — consider folding it.",
            );
        }

        return $flags;
    }

    /**
     * @return Collection<int, Spoke>
     */
    private function spokes(Site $site): Collection
    {
        return Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();
    }
}
