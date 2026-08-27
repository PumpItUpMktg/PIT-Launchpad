<?php

namespace App\Jobs;

use App\Locations\CountyCoverage;
use App\Locations\CoverageWriter;
use App\Models\CoverageArea;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Seeds a site's coverage set — every municipality in its base locations' served counties (Census, joined to
 * ACS population) written as {@see CoverageArea} rows. This is the queued twin of
 * `launchpad:locations-coverage --persist`, so an operator can populate a tenant's county towns from the
 * admin panel; coverage-mode scans then have the whole county to measure. Census lookups are cached, but the
 * first pass hits the API, so it runs off the web request.
 *
 * Jobs run outside the operator's tenant context, so the site is re-loaded with global scopes dropped.
 */
class SeedSiteCoverage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly string $siteId)
    {
        $queue = config('launchpad.geo_grid.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(CountyCoverage $coverage, CoverageWriter $writer): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $result = $coverage->coverage($site);
        if ($result->perBase === []) {
            return;   // no geocoded base with a selected county — nothing to write
        }

        $writer->write($site, $result);
    }
}
