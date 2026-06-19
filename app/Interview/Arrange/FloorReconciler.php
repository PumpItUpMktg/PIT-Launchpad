<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
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

        return new ArrangeResult(['reconciled' => $reconciled], $this->deadSiloFlags($site, $spokes, $pillarsBySilo, $vectors));
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
    private function deadSiloFlags(Site $site, Collection $spokes, Collection $pillarsBySilo, SpokeEmbeddings $vectors): array
    {
        $plan = new PrunePlan(
            $spokes->map(fn (Spoke $s) => PruneRow::fromSpoke($s))->all(),
            false,
            $site->ownPageBar(),
        );

        $flags = [];
        foreach ($plan->deadSilos() as $silo) {
            $pillar = $pillarsBySilo->get($silo);
            // Skip a silo with no pillar, one already placed as a sub-hub (its spokes nest into
            // the parent on purpose), or one the operator confirmed (dismissed the advisory).
            if (! $pillar instanceof Spoke || $pillar->isSubHub() || $pillar->arrangement_source === ArrangementSource::Confirmed) {
                continue;
            }

            // Nothing is auto-applied for a dead silo (the pick is keep-standalone) — it's flagged
            // and blocks Finalize. Accept folds it into the sibling its spokes most cluster into;
            // dismiss keeps it. Only flag an arrangeable pillar.
            if (! $pillar->isArrangeable()) {
                continue;
            }
            $pillar->update(['flagged' => true]);

            $sibling = $this->dominantSibling((string) $silo, $spokes, $vectors);
            $siblingPillar = $sibling !== null ? $pillarsBySilo->get($sibling) : null;
            $into = $sibling !== null ? " into \"{$sibling}\"" : '';
            $flags[] = new ArrangeFlag(
                ArrangeFlagType::DeadSilo,
                $pillar->id,
                "\"{$silo}\" has no core clearing the own-page bar and thin total volume — accept to fold it{$into}, or dismiss to keep it.",
                $siblingPillar instanceof Spoke ? [['id' => (string) $siblingPillar->id, 'name' => (string) $sibling, 'score' => 0.0]] : [],
                $sibling !== null ? ['silo' => $sibling] : [],
            );
        }

        return $flags;
    }

    /**
     * The other silo this silo's spokes most cluster into (nearest semantic neighbor by count) —
     * the fold target an accepted dead-silo uses.
     *
     * @param  Collection<int, Spoke>  $spokes
     */
    private function dominantSibling(string $silo, Collection $spokes, SpokeEmbeddings $vectors): ?string
    {
        $mine = $spokes->reject(fn (Spoke $s) => $s->is_pillar)->where('silo', $silo)->values();
        $outsiders = $spokes->reject(fn (Spoke $s) => $s->is_pillar)->reject(fn (Spoke $s) => (string) $s->silo === $silo)->values();
        if ($mine->isEmpty() || $outsiders->isEmpty()) {
            return null;
        }

        $tally = [];
        foreach ($mine as $spoke) {
            $best = null;
            $bestScore = -1.0;
            foreach ($outsiders as $other) {
                $score = $vectors->similarity($spoke, $other);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = (string) $other->silo;
                }
            }
            if ($best !== null) {
                $tally[$best] = ($tally[$best] ?? 0) + 1;
            }
        }
        if ($tally === []) {
            return null;
        }
        ksort($tally);
        arsort($tally);

        return (string) array_key_first($tally);
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
