<?php

namespace App\Integrations\SearchConsole;

/** One page's Search Console window: impressions, clicks, and the derived CTR. */
final class PageSearchStats
{
    public function __construct(
        public readonly int $impressions,
        public readonly int $clicks,
        public readonly int $days,
    ) {}

    /** Click-through rate as a percentage (one decimal), 0.0 with no impressions. */
    public function ctr(): float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 1) : 0.0;
    }
}
