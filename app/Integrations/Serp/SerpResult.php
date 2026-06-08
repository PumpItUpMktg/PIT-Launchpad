<?php

namespace App\Integrations\Serp;

/**
 * One organic SERP result, normalized.
 */
final class SerpResult
{
    public function __construct(
        public readonly int $position,
        public readonly string $url,
        public readonly string $domain,
    ) {}
}
