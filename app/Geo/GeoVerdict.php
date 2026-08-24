<?php

namespace App\Geo;

/**
 * The detection outcome for one AI answer: was the brand cited, how prominently (rank among recommended
 * businesses, if any), the sentiment toward it, and which competitors the answer named.
 */
final class GeoVerdict
{
    /**
     * @param  list<string>  $competitors
     */
    public function __construct(
        public readonly bool $cited,
        public readonly ?int $position,
        public readonly string $sentiment,   // positive | neutral | negative | absent
        public readonly array $competitors,
    ) {}
}
