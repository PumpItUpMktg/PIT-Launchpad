<?php

namespace App\KeywordGenerator\Cluster;

use App\Enums\IntentLevel;
use App\KeywordGenerator\Scoring\IntentClassifier;
use App\Models\KeywordCorpus;

/**
 * Picks the head term for a cluster — the term the silo is named + hubbed by. Highest volume wins, but a
 * commercial/transactional term is preferred over an informational one at *similar* volume (within a
 * band of the max), so a silo heads on a page people buy from, not a guide. The top ~2 by volume are the
 * SERP-validation candidates.
 */
final class HeadTermSelector
{
    /** A term within this fraction of the cluster max volume counts as "similar volume". */
    private const SIMILAR_BAND = 0.8;

    public function __construct(private readonly IntentClassifier $intent) {}

    /**
     * @param  list<KeywordCorpus>  $members
     */
    public function select(array $members): ?KeywordCorpus
    {
        return $this->ranked($members)[0] ?? null;
    }

    /**
     * The top-N head candidates in preference order — used for SERP-overlap validation (2 candidates).
     *
     * @param  list<KeywordCorpus>  $members
     * @return list<KeywordCorpus>
     */
    public function candidates(array $members, int $limit = 2): array
    {
        return array_slice($this->ranked($members), 0, max(1, $limit));
    }

    /**
     * @param  list<KeywordCorpus>  $members
     * @return list<KeywordCorpus>
     */
    private function ranked(array $members): array
    {
        if ($members === []) {
            return [];
        }

        $maxVolume = max(array_map(fn (KeywordCorpus $m): int => (int) $m->volume, $members));
        $band = (int) floor($maxVolume * self::SIMILAR_BAND);

        $ranked = $members;
        usort($ranked, function (KeywordCorpus $a, KeywordCorpus $b) use ($band): int {
            // Within the similar-volume band, higher-intent wins; otherwise raw volume decides.
            $aIn = (int) $a->volume >= $band;
            $bIn = (int) $b->volume >= $band;
            if ($aIn && $bIn) {
                return [$this->intentWeight($b), (int) $b->volume, $a->canonical]
                    <=> [$this->intentWeight($a), (int) $a->volume, $b->canonical];
            }

            return [(int) $b->volume, $a->canonical] <=> [(int) $a->volume, $b->canonical];
        });

        return $ranked;
    }

    private function intentWeight(KeywordCorpus $term): float
    {
        $intent = $term->intent !== null
            ? (IntentLevel::tryFrom($term->intent) ?? $this->intent->classify($term->term))
            : $this->intent->classify($term->term);

        return $intent->weight();
    }
}
