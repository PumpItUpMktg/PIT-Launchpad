<?php

namespace App\Local\Proof;

use App\Models\Service;

/**
 * The service-scoped reviews source for a spoke/hub page — the SAME {@see LocalReview} DTO the
 * location relay fixed, with a different filter: `review.service == this service` (the provider
 * owns the matching). Contract-first: not deployed yet; the section renders the moment a provider
 * binds. Empty ⇒ the section omits entirely — no headers over nothing, no placeholders.
 * DO NOT emit review/aggregateRating structured data now that a real provider is bound (Review Capture §8):
 * first-party reviews on the business's own site are Google's self-serving-review case — display is fine,
 * markup is a portfolio-wide manual-action risk. The schema builders deliberately stay review-free.
 *
 * @see NullServiceReviews the default binding
 */
interface ServiceReviewProvider
{
    /** @return list<LocalReview> */
    public function for(Service $service): array;
}
