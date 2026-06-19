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
        private readonly float $reflipMargin = 0.05,
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
            $cleared = $best !== null && $score >= $this->relatednessFloor;
            $target = $cleared ? $best : $pillar; // safe fallback to the pillar

            // Margin-to-reflip: don't move an existing auto default unless the new signal beats
            // the current one by a band, not a hair — otherwise near-threshold cases thrash every run.
            if ($spoke->arrangement_source === ArrangementSource::Auto
                && $spoke->fold_into_id !== null
                && $target?->id !== $spoke->fold_into_id
                && $score < ($spoke->arrangement_score ?? -1.0) + $this->reflipMargin) {
                continue; // keep the current target + score
            }

            $lowConfidence = ! $cleared && $cores->isNotEmpty();
            if ($lowConfidence) {
                // No core cleared the floor — parked on the pillar (the pick). Dismiss keeps it
                // there; accept folds it into the best below-floor core (the alternative).
                $flags[] = new ArrangeFlag(
                    ArrangeFlagType::NestLowConfidence,
                    $spoke->id,
                    "No strong parent for \"{$spoke->name}\"; parked on the {$spoke->silo} pillar — accept to nest under \"{$best?->name}\", or dismiss to keep it on the pillar.",
                    $cores->take(3)->map(fn (Spoke $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'score' => round($vectors->similarity($spoke, $c), 3),
                    ])->all(),
                    $best !== null ? ['spoke_id' => $best->id] : [], // accept → fold into the best core
                );
            }

            $targetId = $target?->id;
            $changed = $spoke->fold_into_id !== $targetId;
            $attrs = [
                'fold_into_id' => $targetId,
                'arrangement_source' => ArrangementSource::Auto,
                'arrangement_score' => max($score, 0.0), // -1.0 when no cores existed → 0.0
            ];
            if ($lowConfidence) {
                $attrs['flagged'] = true; // only ever SET (AutoArranger resets at run start)
            }
            $spoke->update($attrs);
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
     * @param  Collection<string, Spoke>  $pillarsBySilo
     * @param  Collection<string, Spoke>  $byId
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
