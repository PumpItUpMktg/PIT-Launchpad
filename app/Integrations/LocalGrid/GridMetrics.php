<?php

namespace App\Integrations\LocalGrid;

/**
 * Normalized geo-grid metrics for a (query × market): average rank, share of
 * top-3 cells, coverage, and the local-pack competitor set.
 */
final class GridMetrics
{
    /**
     * @param  list<LocalPackCompetitor>  $packCompetitors
     */
    public function __construct(
        public readonly string $query,
        public readonly float $avgRank,
        public readonly float $pctTop3,
        public readonly float $coverage,
        public readonly array $packCompetitors = [],
    ) {}
}
