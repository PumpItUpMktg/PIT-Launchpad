<?php

namespace App\Jobs;

use App\Citations\CitationScanner;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\CurrentSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the monthly citation scan for one location on the queue (§ Citations, PR2). Idempotent by
 * (location, directory) — a re-scan updates the same status rows. Sets the tenant scope so every
 * site-scoped read/write in the scanner resolves to the location's own site.
 */
class RunCitationScan implements ShouldQueue
{
    use Queueable;

    /** DataForSEO round-trips take time; cap the whole scan generously. */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly string $locationId,
        public readonly bool $sweepSharedNumbers = true,
    ) {}

    public function handle(CitationScanner $scanner): void
    {
        $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($this->locationId);
        if ($location === null) {
            return;
        }

        CurrentSite::set((string) $location->site_id);

        $scanner->scanLocation($location);

        if ($this->sweepSharedNumbers) {
            $scanner->sweepSharedNumbers((string) $location->site_id);
        }
    }
}
