<?php

namespace App\Local\Proof;

use App\Models\Service;

/**
 * The service-scoped reviews source for a spoke/hub page — the SAME {@see LocalReview} DTO the
 * location relay fixed, with a different filter: `review.service == this service` (the provider
 * owns the matching). Contract-first: not deployed yet; the section renders the moment a provider
 * binds. Empty ⇒ the section omits entirely — no headers over nothing, no placeholders.
 * TODO(reviews-live): when a real provider binds, also unlock the Service review/aggregateRating
 * schema properties (deliberately absent until then — see ServiceSchemaBuilder).
 *
 * @see NullServiceReviews the default binding
 */
interface ServiceReviewProvider
{
    /** @return list<LocalReview> */
    public function for(Service $service): array;
}
