<?php

namespace App\Integrations\News;

use DateTimeImmutable;

/**
 * A normalized news item — the vendor-agnostic contract every news source maps
 * its raw output into, and the convergence point where a feed's origin stops
 * mattering. Google-News-style feeds: default to source_name only and leave url
 * null unless a clean canonical URL resolves (redirect tokens are not a usable
 * link); client direct feeds carry the publisher url straight from <link>.
 *
 * `feedId` is the originating Feed (Source) id — provenance that rides every
 * item so the candidate can be attributed and inherit the feed's routing hint.
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
        public readonly ?string $feedId = null,
    ) {}

    public function text(): string
    {
        return trim($this->title.' '.$this->summary.' '.($this->body ?? ''));
    }
}
