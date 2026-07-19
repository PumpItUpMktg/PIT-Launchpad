<?php

namespace App\KeywordGenerator\Cluster;

use App\Integrations\Embedding\Vectors;
use App\Models\KeywordCorpus;

/**
 * Groups corpus terms into demand clusters by embedding similarity — a deterministic greedy
 * agglomeration: terms are visited in a fixed order (volume desc, then canonical) and each joins the
 * existing cluster whose running centroid is nearest above the cosine threshold, else opens a new one.
 * Order-stable, so the same corpus clusters the same way every run (re-runnable). Labeling / merge /
 * split / drop is a separate Claude pass; this is the raw geometry.
 */
final class ClusterEngine
{
    public function __construct(private readonly CorpusEmbeddings $embeddings) {}

    /**
     * @param  list<KeywordCorpus>  $terms
     * @return list<list<KeywordCorpus>> clusters, each a list of member rows
     */
    public function cluster(array $terms, ?float $threshold = null): array
    {
        if ($terms === []) {
            return [];
        }
        $threshold ??= (float) config('launchpad.keyword_first.cluster_cosine', 0.70);

        $vectors = $this->embeddings->vectors($terms);

        // Deterministic visit order: highest volume first, then canonical for stable ties.
        usort($terms, fn (KeywordCorpus $a, KeywordCorpus $b): int => [$b->volume ?? 0, $a->canonical] <=> [$a->volume ?? 0, $b->canonical]);

        /** @var list<array{members: list<KeywordCorpus>, sum: list<float>, count: int}> $clusters */
        $clusters = [];

        foreach ($terms as $term) {
            $vector = $vectors[$term->canonical];

            $bestIndex = -1;
            $best = -1.0;
            foreach ($clusters as $index => $cluster) {
                $sim = Vectors::cosine($vector, $this->centroid($cluster));
                if ($sim > $best) {
                    $best = $sim;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex >= 0 && $best >= $threshold) {
                $clusters[$bestIndex]['members'][] = $term;
                $clusters[$bestIndex]['sum'] = $this->add($clusters[$bestIndex]['sum'], $vector);
                $clusters[$bestIndex]['count']++;
            } else {
                $clusters[] = ['members' => [$term], 'sum' => $vector, 'count' => 1];
            }
        }

        return array_map(fn (array $cluster): array => $cluster['members'], $clusters);
    }

    /**
     * @param  array{sum: list<float>, count: int}  $cluster
     * @return list<float>
     */
    private function centroid(array $cluster): array
    {
        return array_map(fn (float $v): float => $v / max(1, $cluster['count']), $cluster['sum']);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return list<float>
     */
    private function add(array $a, array $b): array
    {
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $a[$i] += $b[$i];
        }

        return $a;
    }
}
