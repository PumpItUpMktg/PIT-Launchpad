<?php

namespace App\Integrations\Backlinks;

/**
 * One link from the backlink index, normalized: the referring domain and page, the page it points at, and
 * the index's scores and flags. Ranks and spam scores are 0-100.
 */
final readonly class BacklinkRow
{
    public function __construct(
        public string $domainFrom,
        public string $urlFrom,
        public string $urlTo,
        public ?int $domainFromRank,
        public ?int $pageFromRank,
        public ?int $spamScore,
        public bool $dofollow,
        public bool $isBroken,
        public ?string $anchor,
        public ?string $firstSeen,
        public ?string $lastSeen,
    ) {}
}
