<?php

namespace App\Console\Commands;

use App\Locations\TownLocationAssigner;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Backfill the town-page → Location assignment (Live Locations board grouping) for existing
 * tenants. New builds assign at materialize; this covers pages that predate the column. Idempotent.
 */
class AssignTownLocationsCommand extends Command
{
    protected $signature = 'launchpad:assign-town-locations {--site= : limit to one site id}';

    protected $description = 'Assign town pages to the physical Location that serves them (from served_towns; single-location sites assign everything).';

    public function handle(TownLocationAssigner $assigner): int
    {
        $sites = Site::withoutGlobalScope(SiteScope::class)
            ->when($this->option('site'), fn ($q, $site) => $q->whereKey($site))
            ->get();

        foreach ($sites as $site) {
            $result = $assigner->assign($site);
            $this->info(sprintf('%s: %d assigned%s', $site->brand_name ?? $site->id, $result['assigned'],
                $result['unmatched'] === [] ? '' : ' · unmatched: '.implode(', ', $result['unmatched'])));
        }

        return self::SUCCESS;
    }
}
