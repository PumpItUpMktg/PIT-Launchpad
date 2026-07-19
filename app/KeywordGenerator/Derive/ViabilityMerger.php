<?php

namespace App\KeywordGenerator\Derive;

use App\Integrations\Embedding\Vectors;
use App\KeywordGenerator\Cluster\CorpusEmbeddings;
use App\Models\KeywordCluster;
use App\Models\KeywordCorpus;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\SiloCreator\ViabilityGuard;

/**
 * Enforces the viability floor AT DERIVATION — a cluster below the keyword-support threshold (≥3) is
 * folded into its nearest neighbor (by member-embedding centroid) BEFORE a silo ever exists, so thin
 * silos are impossible at output, not flagged after. Persisting: an absorbed cluster's members are
 * repointed to the survivor and the absorbed record is deleted, so the surviving `keyword_clusters` ARE
 * the viable derivation set that services + the demand report read. (Semantic merge/split was already
 * adjudicated by Claude in the L2 labeler; this is the geometric guarantee.)
 */
final class ViabilityMerger
{
    public function __construct(
        private readonly CorpusEmbeddings $embeddings,
        private readonly ViabilityGuard $guard,
    ) {}

    /**
     * @return list<KeywordCluster> the surviving viable clusters
     */
    public function merge(Site $site): array
    {
        $clusters = KeywordCluster::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('dropped', false)
            ->with('members')
            ->get();

        /** @var list<array{model: KeywordCluster, members: list<KeywordCorpus>}> $groups */
        $groups = [];
        foreach ($clusters as $cluster) {
            $members = $cluster->members->all();
            if ($members !== []) {
                $groups[] = ['model' => $cluster, 'members' => $members];
            }
        }

        // Embed every member once for the whole merge.
        $vectors = $this->embeddings->vectors(array_merge(...array_map(fn (array $g): array => $g['members'], $groups)) ?: []);

        while (count($groups) > 1) {
            $thin = $this->smallestThin($groups);
            if ($thin === null) {
                break;
            }

            $nearest = $this->nearestOther($groups, $thin, $vectors);
            if ($nearest === null) {
                break;
            }

            // Fold the thin group into its nearest neighbor (which keeps its head/label).
            $absorbed = $groups[$thin]['model'];
            $survivor = $groups[$nearest]['model'];

            KeywordCorpus::withoutGlobalScope(SiteScope::class)
                ->where('cluster_id', $absorbed->id)
                ->update(['cluster_id' => $survivor->id]);
            $absorbed->delete();

            $groups[$nearest]['members'] = array_merge($groups[$nearest]['members'], $groups[$thin]['members']);
            $survivor->forceFill(['member_count' => count($groups[$nearest]['members'])])->save();

            unset($groups[$thin]);
            $groups = array_values($groups);
        }

        return array_map(fn (array $g): KeywordCluster => $g['model'], $groups);
    }

    /**
     * @param  list<array{model: KeywordCluster, members: list<KeywordCorpus>}>  $groups
     */
    private function smallestThin(array $groups): ?int
    {
        $threshold = $this->guard->threshold();
        $pick = null;
        foreach ($groups as $i => $group) {
            if (count($group['members']) >= $threshold) {
                continue;
            }
            if ($pick === null || count($group['members']) < count($groups[$pick]['members'])) {
                $pick = $i;
            }
        }

        return $pick;
    }

    /**
     * @param  list<array{model: KeywordCluster, members: list<KeywordCorpus>}>  $groups
     * @param  array<string, list<float>>  $vectors
     */
    private function nearestOther(array $groups, int $thin, array $vectors): ?int
    {
        $target = $this->centroid($groups[$thin]['members'], $vectors);
        $best = -1.0;
        $pick = null;
        foreach ($groups as $j => $group) {
            if ($j === $thin) {
                continue;
            }
            $sim = Vectors::cosine($target, $this->centroid($group['members'], $vectors));
            if ($sim > $best) {
                $best = $sim;
                $pick = $j;
            }
        }

        return $pick;
    }

    /**
     * @param  list<KeywordCorpus>  $members
     * @param  array<string, list<float>>  $vectors
     * @return list<float>
     */
    private function centroid(array $members, array $vectors): array
    {
        $sum = [];
        $count = 0;
        foreach ($members as $member) {
            $vector = $vectors[$member->canonical] ?? [];
            if ($vector === []) {
                continue;
            }
            foreach ($vector as $k => $v) {
                $sum[$k] = ($sum[$k] ?? 0.0) + $v;
            }
            $count++;
        }

        return $count === 0 ? [] : array_map(fn (float $v): float => $v / $count, $sum);
    }
}
