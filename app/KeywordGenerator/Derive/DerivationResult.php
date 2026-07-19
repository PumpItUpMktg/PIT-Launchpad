<?php

namespace App\KeywordGenerator\Derive;

/**
 * The outcome of one derivation run. `silos` is the count of viable clusters that became silos (thin
 * clusters were already merged away, so this is the zero-thin output). `demandFindings` is the count of
 * high-demand clusters with no matching service (the business-development report).
 */
final class DerivationResult
{
    public function __construct(
        public readonly int $silos,
        public readonly int $servicesMapped,
        public readonly int $servicesFlagged,
        public readonly int $demandFindings,
    ) {}
}
