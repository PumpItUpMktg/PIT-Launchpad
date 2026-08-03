<?php

namespace App\Integrations\SearchConsole;

/**
 * One Search Console query a page was found for over the window: the search term, its clicks +
 * impressions, the derived CTR, and the average position. This is the free, complete long-tail
 * signal — GSC reports every query that earned an impression (including geo/"near me" variants a
 * location page ranks for), which is exactly what silo keyword tracking deliberately excludes.
 */
final class PageQuery
{
    public function __construct(
        public readonly string $query,
        public readonly int $clicks,
        public readonly int $impressions,
        public readonly float $ctr,
        public readonly float $position,
    ) {}
}
