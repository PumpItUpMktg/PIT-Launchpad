<?php

namespace App\Integrations\Places;

/**
 * A search hit from Find Place / Text Search — enough to show the operator a
 * pick-list before fetching full details.
 */
final class PlaceCandidate
{
    public function __construct(
        public readonly string $placeId,
        public readonly string $name,
        public readonly string $address,
    ) {}
}
