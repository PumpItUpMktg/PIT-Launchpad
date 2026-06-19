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
 * Pass B — cross-silo dedup ("one keyword, one home"). Near-duplicate spokes living
 * in different silos (the live case: *Battery Backup Sump Pump* in Sump Pumps vs
 * *Battery Backup System Installation* in Backup Power) are detected semantically via
 * the §6a embeddings, clustered, and collapsed onto a single home — the highest-volume
 * member, tie-broken by silo fit (how well it sits in its own silo). The rest fold into
 * the winner as sections.
 *
 * The stated-service floor holds: a deduped loser is relocated + folded onto the winner,
 * never deleted (it stays a Candidate and lands as a section at finalize). Only undecided
 * (Candidate) spokes are touched — an operator-confirmed structure is preserved. A clear
 * volume gap auto-resolves silently; a close call still applies the pick but raises a
 * {@see ArrangeFlagType::DedupAmbiguous} flag for the operator to confirm.
 */
final class CrossSiloDedup
{
    public function __construct(
        private readonly float $cosineThreshold = 0.85,
        private readonly float $ambiguityMargin = 0.15,
    ) {}

    public function run(Site $site, SpokeEmbeddings $vectors): ArrangeResult
    {
        $all = $this->spokes($site);
        $pillars = $all->filter(fn (Spoke $s) => $s->is_pillar)->keyBy('silo');
        $candidates = $all->reject(fn (Spoke $s) => $s->is_pillar)->values();

        $applied = 0;
        $flags = [];

        foreach ($this->clusters($candidates, $vectors) as $cluster) {
            // Stable order: highest volume, then strongest silo fit, then name — so the
            // home pick is deterministic across re-runs (no thrash on near-ties).
            usort($cluster, function (Spoke $a, Spoke $b) use ($vectors, $pillars) {
                $ra = $this->rank($a, $vectors, $pillars);
                $rb = $this->rank($b, $vectors, $pillars);

                return [$rb['volume'], $rb['fit'], $ra['name']] <=> [$ra['volume'], $ra['fit'], $rb['name']];
            });

            $winner = $cluster[0];
            $cohesion = $this->maxSimilarity($winner, $cluster, $vectors);

            if ($this->ambiguous($cluster)) {
                $flags[] = new ArrangeFlag(
                    ArrangeFlagType::DedupAmbiguous,
                    $winner->id,
                    "Near-duplicate of {$cluster[1]->name}; auto-arrange kept \"{$winner->name}\" as the home — confirm.",
                    array_map(fn (Spoke $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'score' => round((float) ($s->volume ?? 0), 2),
                    ], $cluster),
                );
            }

            // The winner is the home page; make it a valid own-page fold target (only if undecided).
            if ($winner->isArrangeable()) {
                $winner->update([
                    'granularity' => SpokeGranularity::OwnPage,
                    'fold_into_id' => null,
                    'arrangement_source' => ArrangementSource::Auto,
                    'arrangement_score' => $cohesion,
                ]);
            }

            foreach (array_slice($cluster, 1) as $loser) {
                if (! $loser->isArrangeable()) {
                    continue; // preserve an operator-routed or operator-confirmed spoke
                }
                $loser->update([
                    'silo' => $winner->silo,
                    'granularity' => SpokeGranularity::Folded,
                    'fold_into_id' => $winner->id,
                    'arrangement_source' => ArrangementSource::Auto,
                    'arrangement_score' => $vectors->similarity($winner, $loser),
                ]);
                $applied++;
            }
        }

        return new ArrangeResult(['dedup' => $applied], $flags);
    }

    /**
     * Connected components of the near-dup graph (cosine ≥ threshold), keeping only
     * clusters that span ≥2 distinct silos — this pass is *cross-silo* dedup; a same-silo
     * pair is two legitimate sibling pages, left for the bar/nesting passes.
     *
     * @param  Collection<int, Spoke>  $spokes
     * @return list<list<Spoke>>
     */
    private function clusters(Collection $spokes, SpokeEmbeddings $vectors): array
    {
        $list = $spokes->values()->all();
        $n = count($list);
        $parent = range(0, max(0, $n - 1));

        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($vectors->similarity($list[$i], $list[$j]) >= $this->cosineThreshold) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $groups[$find($i)][] = $list[$i];
        }

        return array_values(array_filter(
            $groups,
            fn (array $g) => count($g) >= 2 && count(array_unique(array_map(fn (Spoke $s) => (string) $s->silo, $g))) >= 2,
        ));
    }

    /**
     * Sort key: volume first, then silo fit (cosine to the spoke's own pillar), then name
     * (the stable tie-break). Null volume sinks to the bottom.
     *
     * @param  Collection<int, Spoke>  $pillars  keyed by silo
     * @return array{volume: int, fit: float, name: string}
     */
    private function rank(Spoke $spoke, SpokeEmbeddings $vectors, Collection $pillars): array
    {
        $pillar = $pillars->get((string) $spoke->silo);
        $fit = $pillar instanceof Spoke ? $vectors->similarity($spoke, $pillar) : 0.0;

        return ['volume' => $spoke->volume ?? -1, 'fit' => $fit, 'name' => $spoke->name];
    }

    /**
     * The strongest similarity between the home spoke and any other cluster member — the
     * dedup's confidence, persisted as the home's arrangement score.
     *
     * @param  list<Spoke>  $cluster
     */
    private function maxSimilarity(Spoke $spoke, array $cluster, SpokeEmbeddings $vectors): float
    {
        $best = 0.0;
        foreach ($cluster as $other) {
            if ($other->id !== $spoke->id) {
                $best = max($best, $vectors->similarity($spoke, $other));
            }
        }

        return $best;
    }

    /**
     * Close call: the top two members have no clear volume gap (relative gap ≤ margin),
     * so silo fit alone decided the home — worth an operator look.
     *
     * @param  list<Spoke>  $cluster  volume-then-fit sorted
     */
    private function ambiguous(array $cluster): bool
    {
        $top = (int) ($cluster[0]->volume ?? 0);
        $next = (int) ($cluster[1]->volume ?? 0);
        $gap = abs($top - $next) / max($top, $next, 1);

        return $gap <= $this->ambiguityMargin;
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
