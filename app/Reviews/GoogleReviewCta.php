<?php

namespace App\Reviews;

use App\Models\Location;

/**
 * Builds the Google "write a review" deep-link for the Location that owns a job's address (§7). This is what
 * fixes wrong-listing routing: the customer chooses nothing — the link goes straight to the right listing,
 * built from the Location's own `place_id` (the Google Places id already stored on the model).
 *
 * Shown to EVERY submitter regardless of star rating — no rating-gated routing, which violates Google policy
 * and would put every tenant's listings at risk. When the Location has no `place_id` the CTA is omitted
 * entirely; there is deliberately no generic-search fallback (that would route to the wrong or no listing).
 */
final class GoogleReviewCta
{
    public function urlFor(?Location $location): ?string
    {
        $placeId = $location?->place_id;
        if ($placeId === null || $placeId === '') {
            return null;
        }

        return 'https://search.google.com/local/writereview?placeid='.rawurlencode($placeId);
    }
}
