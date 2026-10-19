<?php

namespace App\Integrations\Backlinks;

/**
 * A target's backlink totals as the index holds them. `rank` is 0-100 (the index's domain authority).
 */
final readonly class BacklinkSummary
{
    public function __construct(
        public int $backlinks,
        public int $referringDomains,
        public int $brokenBacklinks,
        public ?int $rank,
    ) {}
}
