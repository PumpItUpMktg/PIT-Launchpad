<?php

namespace Tests\Support;

use App\Integrations\News\NewsItem;
use DateTimeImmutable;

/**
 * Builders for news fixtures.
 */
class News
{
    public static function item(string $title, string $summary = 'A relevant home-services development with a homeowner takeaway.', int $ageDays = 5, ?string $topic = null, ?string $url = null, string $source = 'Local Tribune'): NewsItem
    {
        return new NewsItem(
            externalId: 'ext:'.md5($title.$source),
            title: $title,
            summary: $summary,
            sourceName: $source,
            publishedAt: new DateTimeImmutable("-{$ageDays} days"),
            url: $url,
            topic: $topic,
        );
    }
}
