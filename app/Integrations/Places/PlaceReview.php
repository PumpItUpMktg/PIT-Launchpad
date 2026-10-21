<?php

namespace App\Integrations\Places;

/**
 * One Google review as Places reports it — up to five per listing, Google's own pick of the most relevant.
 * Shown verbatim with the author's name and Google's attribution; never edited, never selected by us.
 */
final class PlaceReview
{
    public function __construct(
        public readonly string $authorName,
        public readonly ?string $authorUrl,
        public readonly int $rating,
        public readonly string $text,
        public readonly ?string $relativeTime,
        public readonly ?int $time,
    ) {}
}
