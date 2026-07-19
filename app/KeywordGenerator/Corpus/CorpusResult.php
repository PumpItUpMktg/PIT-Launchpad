<?php

namespace App\KeywordGenerator\Corpus;

/**
 * The outcome of one corpus accumulation run — the breadth checkpoint. `total` is the corpus size after
 * the merge (the number the operator watches: a healthy trade should be hundreds of quality terms; a
 * narrow corpus is the signal to widen expansion beyond related_keywords). `capped` flags that the
 * total-cap guardrail trimmed the tail.
 */
final class CorpusResult
{
    public function __construct(
        public readonly int $added,
        public readonly int $refreshed,
        public readonly int $total,
        public readonly int $seedCount,
        public readonly int $geoFiltered,
        public readonly bool $capped,
    ) {}
}
