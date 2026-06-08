<?php

namespace App\Integrations\News;

use DateTimeInterface;

/**
 * Programmable news source for tests and the default binding (no vendor
 * committed). Returns the items it was given, optionally filtered by `$since`.
 */
class MockNewsProvider implements NewsProvider
{
    /** @var list<NewsItem> */
    private array $items = [];

    public function add(NewsItem $item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * @param  list<NewsItem>  $items
     */
    public function withItems(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $feedConfig
     * @return list<NewsItem>
     */
    public function fetch(array $feedConfig, ?DateTimeInterface $since = null): array
    {
        if ($since === null) {
            return $this->items;
        }

        return array_values(array_filter(
            $this->items,
            fn (NewsItem $item) => $item->publishedAt >= $since,
        ));
    }
}
