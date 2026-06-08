<?php

namespace App\ContentEngine;

use App\Enums\RelevanceBand;

/**
 * The triple-duty relevance outcome: a score + band, the routed silo, an
 * advisory-angle hint, the dimension scores, the local-relevance flag, and the
 * brand-safety gate result.
 */
final class RelevanceResult
{
    public function __construct(
        public readonly float $score,
        public readonly RelevanceBand $band,
        public readonly ?string $matchedSiloId,
        public readonly ?string $angleHint,
        public readonly float $advisoryValue,
        public readonly float $timeliness,
        public readonly bool $localRelevance,
        public readonly bool $brandSafe,
        public readonly string $rationale = '',
    ) {}
}
