<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\ArrangementSource;
use App\Enums\SpokeGranularity;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * Pass A — fold-target assignment (the nesting). For every folded spoke, set its fold
 * target to the most semantically-related own-page core anywhere in its top-level silo
 * *subtree* (its root silo + every sub-hub under it) — this is what nests *Water-Powered
 * Backup* under *Battery Backup*, and (after a Pass C demotion) lets a sub-hub's spoke
 * nest under a core in the parent silo rather than stalling at the sub-hub pillar.
 * Relatedness rides on the §6a embeddings.
 *
 * High-confidence nests (best core ≥ the relatedness floor) auto-resolve silently. When
 * no core clears the floor the spoke falls back to its own silo pillar (always safe) and
 * raises a {@see ArrangeFlagType::NestLowConfidence} flag for a look. Guard: a fold target
 * is only ever an own-page core or a pillar/sub-hub — never another folded page. Only
 * arrangeable folded spokes are retargeted; operator-confirmed folds are preserved.
 */
final class FoldTargetAssigner
{
    public function __construct(
        private readonly float $relatednessFloor = 0.70,
    ) {}

    public function run(Site $site, SpokeEmbeddings $vectors): ArrangeResult
    {
        $all = $this->spokes($site);
        $byId = $all->keyBy('id');
        $pillarsBySilo = $all->filter(fn (Spoke $s) => $s->is_pillar)->keyBy(fn (Spoke $s) => (string) $s->silo);

        // Own-page cores grouped by the top-level silo they belong to (the subtree root).
        $coresByRoot = $all
            ->reject(fn (Spoke $s) => $s->is_pillar)
            ->filter(fn (Spoke $s) => $s->granularity === SpokeGranularity::OwnPage)
            ->groupBy(fn (Spoke $s) => $this->rootSilo((string) $s->silo, $pillarsBySilo, $byId));

        $applied = 0;
        $flags = [];

        $folded = $all
            ->reject(fn (Spoke $s) => $s->is_pillar)
            ->filter(fn (Spoke $s) => $s->granularity === SpokeGranularity::Folded && $s->isArrangeable());

        foreach ($folded as $spoke) {
            $root = $this->rootSilo((string) $spoke->silo, $pillarsBySilo, $byId);
            /** @var Collection<int, Spoke> $cores */
            $cores = $coresByRoot->get($root, collect());
            $pillar = $pillarsBySilo->get((string) $spoke->silo); // fall back to the spoke's own node

            [$best, $score] = $this->bestCore($spoke, $cores, $vectors);

            if ($best !== null && $score >= $this->relatednessFloor) {
                $target = $best;
            } else {
                $target = $pillar; // safe fallback
                if ($cores->isNotEmpty()) {
                    // there were cores to consider but none cleared the floor — flag it
                    $flags[] = new ArrangeFlag(
                        ArrangeFlagType::NestLowConfidence,
                        $spoke->id,
                        "No strong parent for \"{$spoke->name}\"; parked on the {$spoke->silo} pillar — confirm where it nests.",
                        $cores->take(3)->map(fn (Spoke $c) => [
                            'id' => $c->id,
                            'name' => $c->name,
                            'score' => round($vectors->similarity($spoke, $c), 3),
                        ])->all(),
                    );
                }
            }

            $targetId = $target?->id;
            $changed = $spoke->fold_into_id !== $targetId;
            $spoke->update([
                'fold_into_id' => $targetId,
                'arrangement_source' => ArrangementSource::Auto,
                'arrangement_score' => max($score, 0.0), // -1.0 when no cores existed → 0.0
            ]);
            if ($changed) {
                $applied++;
            }
        }

        return new ArrangeResult(['nest' => $applied], $flags);
    }

    /**
     * The top-level silo of a (possibly sub-hub) silo: walk the one-level parent link to
     * the root, else the silo itself.
     *
     * @param  Collection<int, Spoke>  $pillarsBySilo
     * @param  Collection<int, Spoke>  $byId
     */
    private function rootSilo(string $silo, Collection $pillarsBySilo, Collection $byId): string
    {
        $pillar = $pillarsBySilo->get($silo);
        if ($pillar instanceof Spoke && $pillar->isSubHub() && $pillar->parent_silo_id !== null) {
            $parent = $byId->get($pillar->parent_silo_id);
            if ($parent instanceof Spoke) {
                return (string) $parent->silo;
            }
        }

        return $silo;
    }

    /**
     * The most-related own-page core to a folded spoke (name tie-break for determinism).
     *
     * @param  Collection<int, Spoke>  $cores
     * @return array{0: Spoke|null, 1: float}
     */
    private function bestCore(Spoke $spoke, Collection $cores, SpokeEmbeddings $vectors): array
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($cores as $core) {
            if ($core->id === $spoke->id) {
                continue;
            }
            $score = $vectors->similarity($spoke, $core);
            if ($score > $bestScore || ($score === $bestScore && $best !== null && $core->name < $best->name)) {
                $best = $core;
                $bestScore = $score;
            }
        }

        return [$best, $bestScore];
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
