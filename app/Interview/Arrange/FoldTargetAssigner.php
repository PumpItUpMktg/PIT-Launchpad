<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;
use App\Enums\SpokeGranularity;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Collection;

/**
 * Pass A — fold-target assignment (the nesting). For every folded spoke, set its fold
 * target to the most semantically-related own-page core within its (now final, post-
 * dedup) silo — this is what nests *Water-Powered Backup* under *Battery Backup* instead
 * of dumping it at the pillar. Relatedness rides on the §6a embeddings.
 *
 * High-confidence nests (best core ≥ the relatedness floor) auto-resolve silently. When
 * no core clears the floor the spoke falls back to the silo pillar (always safe) and
 * raises a {@see ArrangeFlagType::NestLowConfidence} flag for a look. Guard: a fold
 * target is only ever an own-page core or the pillar — never another folded page. Only
 * undecided (Candidate) folded spokes are retargeted; operator-confirmed folds are
 * preserved. Runs after Pass B so it sees the final silo membership.
 */
final class FoldTargetAssigner
{
    public function __construct(
        private readonly float $relatednessFloor = 0.70,
    ) {}

    public function run(Site $site, SpokeEmbeddings $vectors): ArrangeResult
    {
        $applied = 0;
        $flags = [];

        foreach ($this->spokes($site)->groupBy(fn (Spoke $s) => (string) $s->silo) as $rows) {
            $pillar = $rows->firstWhere('is_pillar', true);
            $cores = $rows
                ->reject(fn (Spoke $s) => $s->is_pillar)
                ->filter(fn (Spoke $s) => $s->granularity === SpokeGranularity::OwnPage)
                ->values();

            $folded = $rows
                ->reject(fn (Spoke $s) => $s->is_pillar)
                ->filter(fn (Spoke $s) => $s->granularity === SpokeGranularity::Folded && $s->isCandidate());

            foreach ($folded as $spoke) {
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
                            "No strong parent for \"{$spoke->name}\"; parked on the {$rows->first()?->silo} pillar — confirm where it nests.",
                            $cores->take(3)->map(fn (Spoke $c) => [
                                'id' => $c->id,
                                'name' => $c->name,
                                'score' => round($vectors->similarity($spoke, $c), 3),
                            ])->all(),
                        );
                    }
                }

                $targetId = $target?->id;
                if ($spoke->fold_into_id !== $targetId) {
                    $spoke->update(['fold_into_id' => $targetId]);
                    $applied++;
                }
            }
        }

        return new ArrangeResult(['nest' => $applied], $flags);
    }

    /**
     * The most-related own-page core to a folded spoke.
     *
     * @param  Collection<int, Spoke>  $cores
     * @return array{0: Spoke|null, 1: float}
     */
    private function bestCore(Spoke $spoke, Collection $cores, SpokeEmbeddings $vectors): array
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($cores as $core) {
            $score = $vectors->similarity($spoke, $core);
            if ($score > $bestScore) {
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
