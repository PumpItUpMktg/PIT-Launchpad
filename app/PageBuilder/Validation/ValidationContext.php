<?php

namespace App\PageBuilder\Validation;

use App\Models\Content;
use App\Models\Market;

/**
 * The runtime context a kit is validated against: the Content being checked,
 * the Market a location page targets (for market-tagged resolution), page flags
 * (e.g. is_storefront), and the recent-work radius.
 */
final class ValidationContext
{
    /**
     * @param  array<string, mixed>  $flags
     */
    public function __construct(
        public readonly Content $content,
        public readonly ?Market $market = null,
        public readonly array $flags = [],
        public readonly int $radiusMiles = 20,
    ) {}

    public function siteId(): string
    {
        return $this->content->site_id;
    }

    public function flag(string $key): mixed
    {
        return $this->flags[$key] ?? null;
    }
}
