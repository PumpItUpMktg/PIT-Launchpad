<?php

namespace App\Console\Commands;

use App\Jobs\RefreshLocationPlaces;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Re-pull the Google Business Profile for GBP-backed locations (§ Citations) and refresh the cached Location +
 * NAP from it. One location (`--location`), a whole site (`--site`), or platform-wide (`--all`, the scheduler's
 * entry point). Only locations with a Places `place_id` (and not merged away) are targeted — the rest have
 * nothing to refresh against. Queues one {@see RefreshLocationPlaces} per location; `--sync` runs inline.
 */
class RefreshPlacesCommand extends Command
{
    protected $signature = 'launchpad:refresh-places
        {--location= : Refresh one location by id}
        {--site= : Refresh every GBP-backed location in a site}
        {--all : Refresh every GBP-backed location platform-wide}
        {--sync : Run inline instead of queueing}';

    protected $description = 'Re-pull GBP data from Places and refresh the cached Location + NAP.';

    public function handle(): int
    {
        $locationIds = $this->resolveLocationIds();
        if ($locationIds === null) {
            $this->error('Pass one of --location=<id>, --site=<id>, or --all.');

            return self::FAILURE;
        }
        if ($locationIds === []) {
            $this->warn('No GBP-backed locations to refresh. Import a Google Business Profile onto a location first.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');

        foreach ($locationIds as $locationId) {
            $job = new RefreshLocationPlaces($locationId);
            $sync ? dispatch_sync($job) : dispatch($job);
        }

        $verb = $sync ? 'Refreshed' : 'Queued refresh for';
        $this->info("{$verb} ".count($locationIds).' location(s).');

        return self::SUCCESS;
    }

    /**
     * The GBP-backed location ids to refresh (a Places `place_id`, not merged away). Null = no selector given.
     *
     * @return list<string>|null
     */
    private function resolveLocationIds(): ?array
    {
        $location = $this->option('location');
        $site = $this->option('site');

        $query = Location::query()->withoutGlobalScope(SiteScope::class)
            ->whereNotNull('place_id')->whereNull('merged_into_id');

        if (is_string($location) && $location !== '') {
            $query->whereKey($location);
        } elseif (is_string($site) && $site !== '') {
            $query->where('site_id', $site);
        } elseif (! $this->option('all')) {
            return null;
        }

        return $query->pluck('id')->map('strval')->all();
    }
}
