<?php

namespace App\Citations\Ui;

use Illuminate\Support\Carbon;

/**
 * One tenant row on the citation portfolio index (§ Citations UI, PR D). Exceptions only — the numbers that
 * drive an operator to click into a tenant: median coverage across its listings, and the counts of the things
 * that need attention (wrong NAP, in flight, stalled).
 */
final readonly class PortfolioRow
{
    public function __construct(
        public string $siteId,
        public string $tenantName,
        public int $listingCount,
        public ?int $medianCoverage,
        public int $mismatchCount,
        public int $submittedCount,
        public int $stalledCount,
        public ?Carbon $lastScanAt,
    ) {}
}
