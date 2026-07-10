<?php

namespace App\Local\Grounding;

use App\Models\Location;

/** Stub seam — google air quality (stub — no current client trade needs it; implement when a mold/HVAC tenant lands). Returns no facts; the trade map can reference it today, the client lands later. */
final class AirQualityProvider implements GroundingProvider
{
    public function fetch(Location $location): array
    {
        return ['facts' => [], 'source' => 'AirQualityProvider'];
    }
}
