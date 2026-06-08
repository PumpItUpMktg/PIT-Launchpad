<?php

namespace App\ContentEngine;

use App\Integrations\News\NewsItem;

/**
 * A themed, ranked cluster of >cutoff backfill items — the silo-discovery corpus
 * the operator mines to select silo builders. Never auto-drafted.
 */
final class DiscoveryCluster
{
    /**
     * @param  list<NewsItem>  $items
     */
    public function __construct(
        public readonly string $theme,
        public readonly array $items,
        public readonly float $rank,
    ) {}

    public function size(): int
    {
        return count($this->items);
    }
}
