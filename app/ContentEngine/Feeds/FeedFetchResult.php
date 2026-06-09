<?php

namespace App\ContentEngine\Feeds;

use App\Integrations\News\NewsItem;

/**
 * The outcome of fetching a single feed: the normalized items plus the telemetry
 * that drives per-feed health (HTTP status, body shape, and a human error when
 * the body wasn't usable RSS/Atom). A non-xml shape is never a silent empty.
 */
final class FeedFetchResult
{
    /**
     * @param  list<NewsItem>  $items
     * @param  string  $format  xml | html | empty | unknown
     */
    public function __construct(
        public readonly array $items,
        public readonly string $format,
        public readonly int $status,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->format === 'xml';
    }
}
