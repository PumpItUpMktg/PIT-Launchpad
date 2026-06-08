<?php

namespace App\Integrations\News;

use DateTimeImmutable;

/**
 * A normalized news item — the vendor-agnostic contract every news source maps
 * its raw output into. Google-News-style feeds: default to source_name only and
 * leave url null unless a clean canonical URL resolves (redirect tokens are not
 * a usable link).
 */
final class NewsItem
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $sourceName,
        public readonly DateTimeImmutable $publishedAt,
        public readonly ?string $url = null,
        public readonly ?string $body = null,
        public readonly ?string $topic = null,
    ) {}

    public function text(): string
    {
        return trim($this->title.' '.$this->summary.' '.($this->body ?? ''));
    }
}
