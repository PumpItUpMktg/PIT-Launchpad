<?php

namespace App\Local\Grounding;

use App\Models\Location;

/**
 * One local-facts source for location-page grounding. Implementations MUST degrade gracefully —
 * a missing API key or failed fetch returns an empty facts list (the caller logs and continues);
 * grounding is never a generation blocker. Facts are short, drafter-ready statements about the
 * location/its served towns; the drafter cites them naturally in copy, never as a data dump.
 */
interface GroundingProvider
{
    /**
     * @return array{facts: list<string>, source: string}
     */
    public function fetch(Location $location): array;
}
