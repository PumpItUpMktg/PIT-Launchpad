<?php

namespace App\Local\Grounding;

use App\Models\Location;

/** Stub seam — usgs water (stub — no Google source for water hardness). Returns no facts; the trade map can reference it today, the client lands later. */
final class WaterProvider implements GroundingProvider
{
    public function fetch(Location $location): array
    {
        return ['facts' => [], 'source' => 'WaterProvider'];
    }
}
