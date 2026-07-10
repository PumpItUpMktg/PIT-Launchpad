<?php

namespace App\Local\Proof;

use App\Models\Location;

/** The default binding until the review-sync system deploys — no reviews, section omits. */
final class NullLocalReviews implements LocalReviewProvider
{
    public function for(Location $location): array
    {
        return [];
    }
}
