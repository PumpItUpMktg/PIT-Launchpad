<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * Pass C — sub-cluster detection → sub-hub demotion recommendation. For each silo it
 * computes the fraction of its (post-dedup) spokes whose nearest semantic neighbor sits
 * in one *other* silo; when that fraction into a single silo Y exceeds the bar (the
 * Backup Power → Sump Pumps case), it recommends demoting this silo to a sub-hub under Y.
 *
 * Always advisory — this pass NEVER mutates structure. It only raises a
 * {@see ArrangeFlagType::SubHubDemotion} flag carrying the target silo + the overlap
 * score as rationale; the {@see SubHubDemoter} applies it only on operator accept.
 * Deterministic: nearest-neighbor ties break by name, so the same set yields the same
 * recommendation. Runs after Pass B (operates on the post-dedup spoke set), and skips
 * silos already demoted (a placed sub-hub) so it never re-flags a settled structure.
 */
final class SubClusterDetector
{
    public function __construct(
        private readonly float $overlapBar = 0.60,
    ) {}

    public function run(Site $site, SpokeEmbeddings $vectors): ArrangeResult
    {
        $spokes = $this->spokes($site);
        $byId = $spokes->keyBy('id');
        $pillars = $spokes->filter(fn (Spoke $s) => $s->is_pillar)->keyBy('silo');
        $candidates = $spokes->reject(fn (Spoke $s) => $s->is_pillar)->values();

        $flags = [];
        $applied = 0;

        foreach ($candidates->groupBy(fn (Spoke $s) => (string) $s->silo) as $silo => $rows) {
            $pillar = $pillars->get((string) $silo);
            if (! $pillar instanceof Spoke || $pillar->arrangement_source === ArrangementSource::Confirmed) {
                continue; // no pillar, or operator already signed off (confirmed/dismissed) — never re-flag
            }

            // An auto sub-hub from a prior run is still UNRESOLVED — re-raise its flag (keep it
            // blocking Finalize) without re-detecting; resolving it (accept/dismiss) confirms it.
            if ($pillar->isSubHub()) {
                $parentPillar = $pillar->parent_silo_id !== null ? $byId->get($pillar->parent_silo_id) : null;
                $parentSilo = $parentPillar instanceof Spoke ? (string) $parentPillar->silo : '';
                $pillar->update(['flagged' => true]);
                $applied++;
                $flags[] = new ArrangeFlag(
                    ArrangeFlagType::SubHubDemotion,
                    $pillar->id,
                    "{$silo} is a sub-hub under {$parentSilo} (auto) — accept, or dismiss to keep it separate.",
                    $parentPillar instanceof Spoke ? [['id' => (string) $parentPillar->id, 'name' => $parentSilo, 'score' => 1.0]] : [],
                    ['keep_separate' => true],
                );

                continue;
            }

            $outsiders = $candidates->reject(fn (Spoke $s) => (string) $s->silo === (string) $silo);
            if ($outsiders->isEmpty()) {
                continue;
            }

            // Tally each spoke's nearest neighbor by the silo it lives in.
            $intoSilo = [];
            foreach ($rows as $spoke) {
                $nearestSilo = $this->nearestNeighborSilo($spoke, $outsiders, $vectors);
                if ($nearestSilo !== null) {
                    $intoSilo[$nearestSilo] = ($intoSilo[$nearestSilo] ?? 0) + 1;
                }
            }
            if ($intoSilo === []) {
                continue;
            }

            // The single dominant target — name as the deterministic tie-break on equal pull.
            ksort($intoSilo);
            arsort($intoSilo);
            $targetSilo = (string) array_key_first($intoSilo);
            $fraction = $intoSilo[$targetSilo] / $rows->count();

            $targetPillar = $pillars->get($targetSilo);
            // One-level cap: a sub-hub target can't host one, and a silo that already hosts
            // sub-hubs can't itself be demoted (pillar → sub-hub → leaf only).
            if ($fraction <= $this->overlapBar
                || ! $targetPillar instanceof Spoke
                || $targetPillar->isSubHub()
                || $this->hasSubHubChildren($pillars, $pillar->id)) {
                continue;
            }

            // Auto-apply the demotion (uniform rule: apply + flag), but never auto-CONFIRM it —
            // it's flagged and blocks Finalize until the operator accepts (keep) or dismisses
            // (un-demote). Pass A (next) re-nests its spokes subtree-aware under the parent.
            $pillar->update([
                'parent_silo_id' => $targetPillar->id,
                'is_sub_hub' => true,
                'arrangement_source' => ArrangementSource::Auto,
                'flagged' => true,
            ]);
            $applied++;

            $flags[] = new ArrangeFlag(
                ArrangeFlagType::SubHubDemotion,
                $pillar->id,
                "Most of {$silo}'s spokes cluster into {$targetSilo}; auto-arrange demoted {$silo} to a sub-hub under {$targetSilo} — accept, or dismiss to keep it separate.",
                [[
                    'id' => $targetPillar->id,
                    'name' => $targetSilo,
                    'score' => round($fraction, 3),
                ]],
                ['keep_separate' => true], // dismiss → un-demote (revert to a top-level silo)
            );
        }

        return new ArrangeResult(['sub_cluster' => $applied], $flags);
    }

    /**
     * Whether any pillar is already parented under this one (it hosts a sub-hub) — so demoting
     * it would breach the one-level cap.
     *
     * @param  Collection<int, Spoke>  $pillars
     */
    private function hasSubHubChildren(Collection $pillars, string $pillarId): bool
    {
        return $pillars->contains(fn (Spoke $p) => $p->parent_silo_id === $pillarId);
    }

    /**
     * The silo of the spoke's most-similar neighbor among the outsiders, ties broken by
     * the neighbor's name for determinism.
     *
     * @param  Collection<int, Spoke>  $outsiders
     */
    private function nearestNeighborSilo(Spoke $spoke, Collection $outsiders, SpokeEmbeddings $vectors): ?string
    {
        $bestSilo = null;
        $bestScore = -1.0;
        $bestName = '';

        foreach ($outsiders as $other) {
            $score = $vectors->similarity($spoke, $other);
            if ($score > $bestScore || ($score === $bestScore && $other->name < $bestName)) {
                $bestScore = $score;
                $bestSilo = (string) $other->silo;
                $bestName = $other->name;
            }
        }

        return $bestSilo;
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
