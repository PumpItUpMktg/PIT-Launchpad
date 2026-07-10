<?php

namespace App\Local\Proof;

/**
 * One local review, already filtered to a location (town ∈ served_towns primary, Haversine radius
 * from the location geo as the fallback — the PROVIDER owns the filtering). The eventual review-sync
 * system implements {@see LocalReviewProvider}; the composer contract is fixed here.
 */
final class LocalReview
{
    public function __construct(
        public readonly string $authorFirst,
        public readonly int $rating,
        public readonly string $text,
        public readonly string $town,
        public readonly ?string $service = null,
        public readonly ?string $date = null,
    ) {}
}
