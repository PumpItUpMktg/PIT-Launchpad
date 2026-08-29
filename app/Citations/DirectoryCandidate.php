<?php

namespace App\Citations;

/**
 * A directory the citation scan keeps surfacing that isn't in the catalog yet (§ Citations, PR5).
 * `occurrences` is how many distinct (site, location) scans saw it — breadth is the promote signal.
 */
final readonly class DirectoryCandidate
{
    public function __construct(
        public string $domain,
        public int $occurrences,
        public ?string $sampleUrl,
    ) {}
}
