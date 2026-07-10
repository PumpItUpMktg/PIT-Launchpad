<?php

namespace App\Local\Grounding;

use App\Models\Location;

/** Stub seam — google pollen (stub). Returns no facts; the trade map can reference it today, the client lands later. */
final class PollenProvider implements GroundingProvider
{
    public function fetch(Location $location): array
    {
        return ['facts' => [], 'source' => 'PollenProvider'];
    }
}
