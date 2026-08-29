<?php

namespace App\Citations\Ui;

use Illuminate\Support\Carbon;

/**
 * One directory row in the location workspace (§ Citations UI, PR C): the directory, its resolved status chip,
 * whether we can submit it, and the NAP-match summary. `sortRank` encodes the default work order —
 * mismatch → stalled → rejected → missing → submitted → live → not-scanned → not-relevant.
 */
final readonly class WorkspaceRow
{
    /**
     * @param  array{key: string, label: string, color: string}  $chip
     */
    public function __construct(
        public ?string $statusId,
        public string $directoryId,
        public string $directoryName,
        public ?string $homepageUrl,
        public ?string $listingUrl,
        public string $tierLabel,
        public bool $isLocal,
        public bool $submittable,
        public array $chip,
        public string $napMatchSummary,
        public ?Carbon $lastCheckedAt,
        public bool $eligible,
        public int $sortRank,
    ) {}
}
