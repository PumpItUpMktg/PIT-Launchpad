<?php

namespace App\Integrations\Places;

/**
 * Capability role: a Google-Places-shaped lookup for the location import flow.
 * `GooglePlacesClient` is the real adapter (Places API); tests bind
 * `MockPlacesProvider`. The operator import action talks to this interface only.
 */
interface PlacesProvider
{
    /**
     * Search a free-text query OR a pasted Maps/GBP URL → candidate hits.
     *
     * @return list<PlaceCandidate>
     */
    public function search(string $query): array;

    /** Full details for a place, or null when it can't be resolved. */
    public function details(string $placeId): ?PlaceDetails;

    /** Confirm the Places API is enabled/reachable (clear reason when not). */
    public function smokeTest(): PlacesStatus;
}
