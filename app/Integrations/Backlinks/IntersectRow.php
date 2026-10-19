<?php

namespace App\Integrations\Backlinks;

/**
 * One referring domain from a domain intersection: which of the asked targets it links to (as the target
 * strings that were passed in), its rank and spam score (0-100), and when the index first saw it.
 */
final readonly class IntersectRow
{
    /** @param  list<string>  $linkedTargets */
    public function __construct(
        public string $domain,
        public ?int $rank,
        public ?int $spamScore,
        public array $linkedTargets,
        public ?string $firstSeen,
    ) {}
}
