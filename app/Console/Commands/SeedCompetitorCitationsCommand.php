<?php

namespace App\Console\Commands;

use App\Citations\CompetitorCitationSeeder;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Support\CurrentSite;
use Illuminate\Console\Command;

/**
 * Seed directory candidates from a competitor's listing footprint (§ Citations, PR8). Reverse-looks-up where
 * the competitor is listed and persists the unmatched domains as catalog candidates for the operator to
 * promote. Grows the catalog from the competition.
 */
class SeedCompetitorCitationsCommand extends Command
{
    protected $signature = 'launchpad:seed-competitor-citations {--location= : Location id (required)} {--competitor= : Competitor business name (required)} {--domain= : Competitor website domain (optional)}';

    protected $description = 'Seed directory candidates by reverse-looking-up a competitor\'s citation footprint.';

    public function handle(CompetitorCitationSeeder $seeder): int
    {
        $locationId = $this->option('location');
        $competitor = $this->option('competitor');
        if (! is_string($locationId) || $locationId === '' || ! is_string($competitor) || $competitor === '') {
            $this->error('Pass --location=<id> and --competitor=<name>.');

            return self::FAILURE;
        }

        $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($locationId);
        if ($location === null) {
            $this->error("No location {$locationId}.");

            return self::FAILURE;
        }
        CurrentSite::set((string) $location->site_id);

        $domain = $this->option('domain');
        $tally = $seeder->seed($location, $competitor, is_string($domain) && $domain !== '' ? $domain : null);

        $this->info("Competitor '{$competitor}': saw {$tally['seen']} domains, {$tally['matched']} already cataloged, "
            ."{$tally['candidates']} new candidates seeded.");

        return self::SUCCESS;
    }
}
