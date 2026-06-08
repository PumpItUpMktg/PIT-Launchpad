<?php

namespace App\ContentEngine;

/**
 * The first-run backfill outcome: the >cutoff silo-discovery corpus (not posts)
 * plus the normal funnel result for ≤cutoff items.
 */
final class BackfillResult
{
    /**
     * @param  list<DiscoveryCluster>  $corpus
     */
    public function __construct(
        public readonly array $corpus,
        public readonly FunnelResult $recent,
    ) {}
}
