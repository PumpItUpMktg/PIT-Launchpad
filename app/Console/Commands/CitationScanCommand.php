<?php

namespace App\Console\Commands;

use App\Jobs\RunCitationScan;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Trigger the citation scan (§ Citations). Scans one location (`--location`), every location in a site
 * (`--site`), or every location platform-wide (`--all`) — but only locations that have a canonical NAP profile
 * (the scan skips the rest, so there's nothing to run without one). Queues one {@see RunCitationScan} per
 * location by default; `--sync` runs inline. This is the operator/scheduler entry point.
 */
class CitationScanCommand extends Command
{
    protected $signature = 'launchpad:citation-scan
        {--location= : Scan one location by id}
        {--site= : Scan every NAP-profiled location in a site}
        {--all : Scan every NAP-profiled location platform-wide}
        {--sync : Run inline instead of queueing}
        {--no-sweep : Skip the tenant shared-number sweep}';

    protected $description = 'Trigger the citation scan for a location, a site, or the whole platform.';

    public function handle(): int
    {
        $locationIds = $this->resolveLocationIds();
        if ($locationIds === null) {
            $this->error('Pass one of --location=<id>, --site=<id>, or --all.');

            return self::FAILURE;
        }
        if ($locationIds === []) {
            $this->warn('No NAP-profiled locations to scan. Add a Location NAP profile first.');

            return self::SUCCESS;
        }

        $sweep = ! $this->option('no-sweep');
        $sync = (bool) $this->option('sync');
        $trigger = $this->option('all') ? 'scheduled' : 'manual';

        foreach ($locationIds as $locationId) {
            $job = new RunCitationScan($locationId, sweepSharedNumbers: $sweep, trigger: $trigger);
            $sync ? dispatch_sync($job) : dispatch($job);
        }

        $verb = $sync ? 'Scanned' : 'Queued scans for';
        $this->info("{$verb} ".count($locationIds).' location(s).');

        return self::SUCCESS;
    }

    /**
     * The location ids to scan, restricted to those with a NAP profile. Null = no target selector was given.
     *
     * @return list<string>|null
     */
    private function resolveLocationIds(): ?array
    {
        $location = $this->option('location');
        $site = $this->option('site');

        if (is_string($location) && $location !== '') {
            $ids = [$location];
        } elseif (is_string($site) && $site !== '') {
            $ids = Location::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site)->pluck('id')->map('strval')->all();
        } elseif ($this->option('all')) {
            $ids = Location::query()->withoutGlobalScope(SiteScope::class)->pluck('id')->map('strval')->all();
        } else {
            return null;
        }

        // Keep only locations that have a canonical NAP profile — the scan skips the rest anyway.
        $profiled = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
            ->whereIn('location_id', $ids)->pluck('location_id')->map('strval')->all();

        return array_values(array_intersect($ids, $profiled));
    }
}
