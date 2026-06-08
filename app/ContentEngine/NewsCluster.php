<?php

namespace App\ContentEngine;

use App\Integrations\News\NewsItem;

/**
 * A same-story cluster: one representative item plus the multi-outlet coverage
 * collapsed into it.
 */
final class NewsCluster
{
    /**
     * @param  list<NewsItem>  $members
     */
    public function __construct(
        public readonly NewsItem $representative,
        public readonly array $members,
    ) {}

    /**
     * @return list<string>
     */
    public function sourceNames(): array
    {
        return array_values(array_unique(array_map(fn (NewsItem $i) => $i->sourceName, $this->members)));
    }

    public function outletCount(): int
    {
        return count($this->members);
    }
}
