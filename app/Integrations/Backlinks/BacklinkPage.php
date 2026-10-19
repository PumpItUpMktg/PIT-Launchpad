<?php

namespace App\Integrations\Backlinks;

/**
 * One page of backlink rows plus the cursor for the next (null when this was the last).
 */
final readonly class BacklinkPage
{
    /** @param  list<BacklinkRow>  $rows */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public ?string $nextToken,
    ) {}
}
