<?php

namespace App\Integrations\LocalGrid;

/**
 * Capability role: a local-grid provider (Local Falcon, BrightLocal, …). Maps
 * raw geo-grid output into the normalized GridMetrics contract. Beatability and
 * local-lane tracking consume this interface only.
 */
interface LocalGridProvider
{
    public function grid(string $query, string $marketId): GridMetrics;
}
