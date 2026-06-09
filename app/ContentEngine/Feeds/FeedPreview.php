<?php

namespace App\ContentEngine\Feeds;

/**
 * The result of validate-on-add: either an actionable error (shown inline before
 * the feed is saved) or a preview — the resolved publisher name plus a few
 * sample headlines so the client can confirm they pasted the right feed.
 */
final class FeedPreview
{
    /**
     * @param  list<string>  $samples
     */
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $error = null,
        public readonly ?string $publisher = null,
        public readonly array $samples = [],
    ) {}

    /**
     * @param  list<string>  $samples
     */
    public static function ok(string $publisher, array $samples): self
    {
        return new self(true, null, $publisher, $samples);
    }

    public static function invalid(string $error): self
    {
        return new self(false, $error);
    }
}
