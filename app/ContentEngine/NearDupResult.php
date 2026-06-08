<?php

namespace App\ContentEngine;

use App\Enums\NearDupTier;

/**
 * The near-duplicate decision and the strongest overlap signal behind it.
 */
final class NearDupResult
{
    public function __construct(
        public readonly NearDupTier $tier,
        public readonly ?string $similarToContentId,
        public readonly float $semanticSimilarity,
        public readonly float $keywordOverlap,
    ) {}

    public function signal(): float
    {
        return max($this->semanticSimilarity, $this->keywordOverlap);
    }
}
