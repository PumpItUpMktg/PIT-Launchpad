<?php

namespace App\Console\Commands;

use App\Locations\CoverageRepair;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Repairs a tenant's service-area coverage data ({@see CoverageRepair}): strips the numbered-list name
 * artifact ("1, Abingdon"), removes out-of-territory towns (e.g. Maryland towns on a New Jersey site) and
 * prunes the county that sourced them, and collapses intra-county duplicates. Fixes both the homepage
 * "Areas we serve" store (coverage_areas) and the location-page served-towns lists.
 *
 * PREVIEW BY DEFAULT — lists every change and touches nothing until `--apply`. A site id/brand scopes it
 * to one tenant; omit the argument to sweep every tenant (each judged against its OWN territory).
 *
 * After an --apply, re-materialize / repush the homepage + location pages so they render the cleaned set.
 */
class RepairCoverageCommand extends Command
{
    protected $signature = 'launchpad:repair-coverage {site? : Site id or brand name (omit to sweep every tenant)}
        {--apply : Actually apply the repairs (default is a preview that changes nothing)}';

    protected $description = 'Repair service-area coverage: strip name prefixes, drop out-of-territory towns, dedupe intra-county. Preview by default; --apply to fix.';

    public function handle(CoverageRepair $repair): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $touched = 0;

        foreach ($sites as $site) {
            $r = $repair->repair($site, $apply);

            $changes = count($r['coverage']['prefix_cleaned']) + count($r['coverage']['out_of_state']) + count($r['coverage']['deduped'])
                + $r['served']['prefix_cleaned'] + $r['served']['out_of_state'] + $r['served']['deduped'];
            if ($changes === 0) {
                continue;
            }
            $touched++;

            $this->newLine();
            $terr = $r['territory'] === [] ? 'UNKNOWN (out-of-territory pass skipped)' : implode(', ', $r['territory']);
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — territory: {$terr}");

            $this->reportList('coverage · name prefix', $r['coverage']['prefix_cleaned']);
            $this->reportList('coverage · out-of-territory (removed)', $r['coverage']['out_of_state']);
            $this->reportList('coverage · exact duplicate (removed)', $r['coverage']['deduped']);
            $this->reportList('coverage · same-name, kept & disambiguated by county at render', $r['coverage']['disambiguated']);
            if ($r['counties_pruned'] !== []) {
                $this->line('  • pruned out-of-territory county FIPS from locations: '.implode(', ', $r['counties_pruned']));
            }
            if ($r['served']['locations_touched'] > 0) {
                $this->line(sprintf(
                    '  • served_towns on %d location(s): %d prefix, %d out-of-territory, %d duplicate',
                    $r['served']['locations_touched'],
                    $r['served']['prefix_cleaned'],
                    $r['served']['out_of_state'],
                    $r['served']['deduped'],
                ));
            }
        }

        $this->newLine();
        if ($touched === 0) {
            $this->info('Coverage is clean — no prefixes, out-of-territory towns, or duplicates found.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->comment("Preview only — nothing changed. Re-run with --apply to repair {$touched} tenant(s).");

            return self::SUCCESS;
        }

        $this->info("Repaired coverage for {$touched} tenant(s). Re-materialize / repush the homepage + location pages to render the cleaned set.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $items */
    private function reportList(string $label, array $items): void
    {
        if ($items === []) {
            return;
        }
        $this->line("  • {$label}: ".implode(' · ', $items));
    }

    /** @return Collection<int, Site>|null */
    private function resolveSites(): ?Collection
    {
        $arg = $this->argument('site');
        if ($arg === null) {
            return Site::query()->get();
        }

        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return null;
        }

        return collect([$site]);
    }
}
