<?php

namespace App\Integrations\Places;

/**
 * The public face of a Google Business Profile as Places reports it (§ Roadmap): what a searcher sees in
 * the pack and what the pack ranks on — rating, how many ratings, the Google types (the first is the
 * primary category's type), photo count, whether it is open for business, and where it is.
 */
final class PlaceProfile
{
    /**
     * @param  list<string>  $types
     * @param  list<PlaceReview>  $reviews  Google's five most relevant reviews, when the listing has any
     */
    public function __construct(
        public readonly string $placeId,
        public readonly string $name,
        public readonly ?float $rating,
        public readonly ?int $ratingsTotal,
        public readonly array $types,
        public readonly ?int $photoCount,
        public readonly ?string $businessStatus,
        public readonly ?float $lat,
        public readonly ?float $lng,
        public readonly ?string $website,
        public readonly ?string $gbpUrl,
        public readonly array $reviews = [],
    ) {}

    /** The primary Google type — the first non-generic one. */
    public function primaryType(): ?string
    {
        foreach ($this->types as $t) {
            if (! in_array($t, ['point_of_interest', 'establishment'], true)) {
                return $t;
            }
        }

        return $this->types[0] ?? null;
    }
}
