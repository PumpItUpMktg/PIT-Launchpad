<?php

namespace App\Integrations\News;

use DateTimeImmutable;

/**
 * Programmable on-demand source pull for tests and the default binding.
 */
class MockOnDemandSourcePull implements OnDemandSourcePull
{
    /** @var array<string, NewsItem> */
    private array $items = [];

    public function set(string $topic, NewsItem $item): static
    {
        $this->items[$topic] = $item;

        return $this;
    }

    public function pull(string $topic): ?NewsItem
    {
        return $this->items[$topic] ?? new NewsItem(
            externalId: 'ondemand:'.md5($topic),
            title: $topic,
            summary: 'Source material for: '.$topic,
            sourceName: 'On-Demand Pull',
            publishedAt: new DateTimeImmutable,
            topic: $topic,
        );
    }
}
