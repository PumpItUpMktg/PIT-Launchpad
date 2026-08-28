<?php

namespace App\Jobs;

use App\GeoGrid\GeoGridScanner;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * POSTS one coverage scan — a single (location × keyword) — on the queue: create the scan and post one
 * DataForSEO Maps task per served town, then return. The results are COLLECTED and the aggregates derived
 * later by the {@see IngestCoverageScans} sweep. Posting is split from collection because a whole-county scan
 * is 100+ rate-limited task_get calls — far past any single job's timeout (this job previously timed out on
 * counties once coverage scans went county-wide). Posting is just a couple of rate-limited task_post calls.
 *
 * Metered + retryable via the daily cadence, so `tries = 1`. Jobs run outside the operator's tenant context,
 * so models are re-loaded with {@see SiteScope} dropped.
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

    public function handle(GeoGridScanner $scanner): void
    {
        $location = Location::withoutGlobalScope(SiteScope::class)->find($this->locationId);
        $keyword = Keyword::withoutGlobalScope(SiteScope::class)->find($this->keywordId);
        if ($location === null || $keyword === null) {
            return;
        }

        $scanner->postCoverageScan($location, $keyword);
    }
}
