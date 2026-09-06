<?php

namespace App\ContentEngine;

use App\Enums\CandidateClassification;
use App\Enums\CandidateScope;
use App\Enums\RelevanceBand;
use App\Enums\ShelfLife;

/**
 * The triple-duty relevance outcome: a score + band, the routed silo, an
 * advisory-angle hint, the dimension scores, the local-relevance flag, the
 * brand-safety gate result, the operator-facing timeliness classification, and
 * the competitor-announcement gate flag.
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
        public readonly CandidateClassification $classification = CandidateClassification::Evergreen,
        public readonly bool $competitorPromo = false,
        // The two orthogonal classification axes (shelf-life × scope) — the successor to the conflated
        // single `classification`. Derived from timeliness + local_relevance; stamped on the candidate.
        public readonly ShelfLife $shelfLife = ShelfLife::Evergreen,
        public readonly CandidateScope $scope = CandidateScope::General,
    ) {}
}
