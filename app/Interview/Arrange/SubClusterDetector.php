<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
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
        $pillars = $spokes->filter(fn (Spoke $s) => $s->is_pillar)->keyBy('silo');
        $candidates = $spokes->reject(fn (Spoke $s) => $s->is_pillar)->values();

        $flags = [];

        foreach ($candidates->groupBy(fn (Spoke $s) => (string) $s->silo) as $silo => $rows) {
            $pillar = $pillars->get((string) $silo);
            // Skip silos with no pillar, or already-demoted sub-hubs (already placed).
            if (! $pillar instanceof Spoke || $pillar->isSubHub()) {
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
            // Respect the one-level cap up front: a target that is itself a sub-hub can't host one.
            if ($fraction <= $this->overlapBar || ! $targetPillar instanceof Spoke || $targetPillar->isSubHub()) {
                continue;
            }

            $flags[] = new ArrangeFlag(
                ArrangeFlagType::SubHubDemotion,
                $pillar->id,
                "Most of {$silo}'s spokes cluster into {$targetSilo} — consider demoting {$silo} to a sub-hub under {$targetSilo}.",
                [[
                    'id' => $targetPillar->id,
                    'name' => $targetSilo,
                    'score' => round($fraction, 3),
                ]],
            );
        }

        return new ArrangeResult(['sub_cluster' => count($flags)], $flags);
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
