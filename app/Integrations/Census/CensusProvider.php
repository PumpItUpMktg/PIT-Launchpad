<?php

namespace App\Integrations\Census;

/**
 * Capability role: Census ACS demographics enrichment for a market geo.
 * Deferred — mocked for now.
 */
interface CensusProvider
{
    /**
     * @return array<string, mixed>
     */
    public function demographics(string $geoId): array;
}
