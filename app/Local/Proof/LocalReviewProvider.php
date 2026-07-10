<?php

namespace App\Local\Proof;

use App\Models\Location;

/**
 * The local-reviews source for a location page — not deployed yet; this contract ships first so the
 * section renders the moment a provider binds. Filtering to the location (served-town membership,
 * else a ~20mi Haversine radius) is the provider's job. Empty ⇒ the section omits entirely.
 * TODO(reviews-live): when a real provider binds, also unlock the LocalBusiness review/aggregateRating
 * schema properties (deliberately absent until then — see LocationSchemaBuilder).
 *
 * @see NullLocalReviews the default binding
 */
interface LocalReviewProvider
{
    /** @return list<LocalReview> */
    public function for(Location $location): array;
}
