<?php

namespace App\Integrations\Google;

/**
 * A normalized GSC search-analytics row: the dimension keys (query/page/…) plus
 * the first-party metrics. This is the §5 calibration shape (first-party signal,
 * distinct from DataForSEO's third-party SERP).
 */
final class SearchAnalyticsRow
{
    /**
     * @param  list<string>  $keys  values for the requested dimensions, in order
     */
    public function __construct(
        public readonly array $keys,
        public readonly int $clicks,
        public readonly int $impressions,
        public readonly float $ctr,
        public readonly float $position,
    ) {}
}
