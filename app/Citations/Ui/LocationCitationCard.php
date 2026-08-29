<?php

namespace App\Citations\Ui;

use Illuminate\Support\Carbon;

/**
 * The per-location card the tenant citation board renders (§ Citations UI). One physical location: its GBP
 * attachment state, the canonical NAP the scan compares against, the coverage breakdown (live / mismatch /
 * submitted / missing across the eligible directories), and the scan state that drives whether the card shows
 * a coverage bar, a launch button, or a running progress bar.
 */
final readonly class LocationCitationCard
{
    /**
     * @param  'never'|'scanned'|'scanning'  $scanState
     * @param  array<string, mixed>  $nap
     */
    public function __construct(
        public string $locationId,
        public string $name,
        public string $typeLabel,       // Storefront | Service area
        public bool $hasGbp,
        public bool $hasNap,
        public array $nap,
        public int $eligible,
        public int $live,
        public int $mismatch,
        public int $submitted,
        public int $missing,
        public ?int $coveragePercent,
        public string $scanState,
        public ?Carbon $lastScannedAt,
    ) {}

    public function isScanning(): bool
    {
        return $this->scanState === 'scanning';
    }

    public function neverScanned(): bool
    {
        return $this->scanState === 'never';
    }
}
