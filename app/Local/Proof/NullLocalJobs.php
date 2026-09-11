<?php

namespace App\Local\Proof;

use App\Models\Location;

/** The default binding until field job capture deploys — no jobs, section omits. */
final class NullLocalJobs implements LocalJobProvider
{
    public function for(Location $location): array
    {
        return [];
    }

    public function near(string $siteId, float $lat, float $lng, float $radius): array
    {
        return [];
    }
}
