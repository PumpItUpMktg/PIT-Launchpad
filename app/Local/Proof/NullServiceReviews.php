<?php

namespace App\Local\Proof;

use App\Models\Service;

/** No review source is deployed yet — the reviews section stays omitted (never a placeholder). */
final class NullServiceReviews implements ServiceReviewProvider
{
    public function for(Service $service): array
    {
        return [];
    }
}
