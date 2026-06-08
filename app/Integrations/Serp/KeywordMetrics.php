<?php

namespace App\Integrations\Serp;

/**
 * Normalized keyword metrics — the vendor-agnostic contract every SERP/keyword
 * provider maps its raw output into.
 */
final class KeywordMetrics
{
    /**
     * @param  list<string>  $relatedTerms
     */
    public function __construct(
        public readonly string $query,
        public readonly int $volume,
        public readonly int $difficulty,
        public readonly array $relatedTerms = [],
    ) {}
}
