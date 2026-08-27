<?php

namespace App\Jobs;

use App\Console\Commands\GeoGridCoverageScanCommand;
use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\GeoGridScanner;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs ONE coverage scan — a single (location × keyword) — on the queue: scan the location's served towns via
 * DataForSEO, then derive its aggregates. This is one pair of the {@see GeoGridCoverageScanCommand}
 * loop, extracted so a scheduled plan fans out one job per keyword (each ~a few minutes under the DataForSEO
 * rate limit) rather than one long-running job. Metered + retryable via the daily cadence, so `tries = 1`.
 *
 * Jobs run outside the operator's tenant context, so models are re-loaded with {@see SiteScope} dropped.
 */
class RunCoverageScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly string $locationId,
        public readonly string $keywordId,
    ) {
        $queue = config('launchpad.geo_grid.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(GeoGridScanner $scanner, GeoGridMetrics $metrics): void
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->find($this->locationId);
        $keyword = Keyword::withoutGlobalScope(SiteScope::class)->find($this->keywordId);
        if ($location === null || $keyword === null) {
            return;
        }

        $metrics->recompute($scanner->scanCoverage($location, $keyword));
    }
}
