<?php

namespace App\KeywordGenerator\Pipeline;

/**
 * Outcome of one site's §5 refresh — what ran and how much it produced. Returned
 * to the operator action for inline reporting; no run-log is persisted.
 */
final class SitePipelineRefreshResult
{
    public function __construct(
        public readonly bool $discoveryRan,
        public readonly int $keywordsScored,
        public readonly bool $trackingRan,
        public readonly int $snapshots,
        public readonly int $keywordsGenerated = 0,
    ) {}
}
