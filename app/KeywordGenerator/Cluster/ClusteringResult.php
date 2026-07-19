<?php

namespace App\KeywordGenerator\Cluster;

/**
 * The outcome of one clustering run. `serpCalls` is the SERP spend (head-candidate validation only) —
 * logged so the cost stays visible per the pipeline's one-SERP-spend rule.
 */
final class ClusteringResult
{
    public function __construct(
        public readonly int $clusters,
        public readonly int $dropped,
        public readonly int $serpCalls,
    ) {}
}
