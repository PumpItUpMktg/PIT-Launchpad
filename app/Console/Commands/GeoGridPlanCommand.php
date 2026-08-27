<?php

namespace App\Console\Commands;

use App\GeoGrid\GeoGridGeometry;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Geo-grid DRY RUN (§7 cost brake): for a site, count the grid-ready locations × grid-opted keywords ×
 * points-per-scan, and report the total DataForSEO request count + estimated cost — WITHOUT calling any
 * API. The scan command (PR 3) enforces the hard request ceiling; this makes the blast radius legible
 * before a credit is spent. A grid scanner with a loop bug is the most expensive kind of bug here, so the
 * brakes ship before the engine.
 */
class GeoGridPlanCommand extends Command
{
    protected $signature = 'launchpad:geo-grid-plan {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'Dry-run a geo-grid scan: locations × grid keywords × points → total requests + estimated cost. No API calls.';

    public function handle(GeoGridGeometry $geometry): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return self::FAILURE;
        }

        /** @var Site $site */
        $site = $matches->first();

        $gridSize = max(1, (int) config('launchpad.geo_grid.grid_size', 7));
        $ceiling = max(0, (int) config('launchpad.geo_grid.request_ceiling', 5000));
        $costPer = (float) config('launchpad.geo_grid.cost_per_request', 0.002);
        $pointsPerScan = $geometry->pointCount($gridSize);

        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->gridReady()->get();
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('is_grid_keyword', true)->count();

        $requests = $locations->count() * $keywords * $pointsPerScan;
        $cost = $requests * $costPer;

        $this->info($site->brand_name ?: (string) $site->id);
        $this->table(['Metric', 'Value'], [
            ['Grid-ready locations', (string) $locations->count()],
            ['Grid keywords', (string) $keywords],
            ['Points / scan', "{$gridSize}×{$gridSize} = {$pointsPerScan}"],
            ['Total DataForSEO requests', number_format($requests)],
            ['Estimated cost', '$'.number_format($cost, 2)."  (@ \${$costPer}/req — verify pricing)"],
            ['Hard request ceiling', number_format($ceiling)],
        ]);

        if ($locations->isNotEmpty()) {
            $this->line('<info>Grid-ready locations</info>:');
            foreach ($locations as $loc) {
                $this->line(sprintf('  %s  (%.5f, %.5f, spacing %.2f mi)',
                    $loc->name ?: $loc->id, (float) $loc->lat, (float) $loc->lng, $loc->gridSpacingMiles()));
            }
        }

        $this->newLine();
        if ($locations->isEmpty() || $keywords === 0) {
            $this->comment('Nothing to scan — need at least one GBP-backed, grid-ready location and one grid keyword (is_grid_keyword).');

            return self::SUCCESS;
        }
        if ($ceiling > 0 && $requests > $ceiling) {
            $this->warn("This plan ({$requests} requests) EXCEEDS the hard ceiling ({$ceiling}). A real scan would abort — narrow the keywords/locations or raise LAUNCHPAD_GEO_GRID_REQUEST_CEILING.");
        } else {
            $this->comment('Dry run only — no API calls made. The scan command (PR 3) will run this plan against DataForSEO.');
        }

        return self::SUCCESS;
    }
}
