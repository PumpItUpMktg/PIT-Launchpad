<?php

namespace App\Local\Proof;

use App\Models\Location;

/**
 * The local-reviews source for a location page — not deployed yet; this contract ships first so the
 * section renders the moment a provider binds. Filtering to the location (served-town membership,
 * else a ~20mi Haversine radius) is the provider's job. Empty ⇒ the section omits entirely.
 * DO NOT emit review/aggregateRating structured data now that a real provider is bound (Review Capture §8):
 * first-party reviews on the business's own site are Google's self-serving-review case — display is fine,
 * markup is a portfolio-wide manual-action risk. The schema builders deliberately stay review-free.
 *
 * @see NullLocalReviews the default binding
 */
interface LocalReviewProvider
{
    /** @return list<LocalReview> */
    public function for(Location $location): array;
}
