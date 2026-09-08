<?php

namespace App\Console\Commands;

use App\Locations\TownPageGeoAnchor;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Anchor town pages to their census GEOID (`contents.geo_id`), derived from the current name-match — the
 * prerequisite that turns the job→town-page chain into a pure GEOID join with no name-match at any hop.
 *
 * REPORT-FIRST: deriving a clean key from a dirty match, so it writes nothing by default. It reports what
 * WOULD be anchored and — the part that matters — surfaces every page it will NOT touch: ambiguous (a name
 * matching several reachable coverage areas — Washington / Springfield / Montgomery), unreachable (a name
 * whose coverage the page's parent doesn't serve — a mis-parent signal), and no-coverage. A human reads
 * that before `--execute` writes the unambiguous set. All tenants, or one via --site.
 */
class AnchorTownPagesCommand extends Command
{
    protected $signature = 'launchpad:anchor-town-pages
        {--site= : Limit to one site id or brand name}
        {--execute : Write geo_id for the unambiguous set (default: report only, write nothing)}';

    protected $description = 'Anchor town pages to their census GEOID from the name-match (report-first; --execute writes).';

    public function handle(TownPageGeoAnchor $anchor): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $execute = (bool) $this->option('execute');
        $this->info($execute
            ? 'EXECUTE · writing geo_id for the unambiguous set only; ambiguous / unreachable / no-coverage pages stay null.'
            : 'Read-only · town-page geo-anchor plan. Nothing is written (pass --execute to anchor the unambiguous set).');

        $grandUnresolved = 0;
        foreach ($sites as $site) {
            $plan = $anchor->plan($site);
            if ($plan['total'] === 0) {
                continue;
            }

            $unresolved = count($plan['ambiguous']) + count($plan['unreachable']) + count($plan['no_coverage']);
            $grandUnresolved += $unresolved;

            $this->newLine();
            $this->line("<options=bold>=== {$site->brand_name} ({$site->id}) ===</>");
            $this->line("  {$plan['total']} town page(s) · {$plan['already']} already anchored · ".count($plan['anchorable'])." anchorable · {$unresolved} to surface");

            // The pages a human must resolve — never guessed.
            $this->surface('AMBIGUOUS — name matches several reachable coverage areas (pick the geo_id by hand)', $plan['ambiguous'], withCandidates: true);
            $this->surface('UNREACHABLE — name matches coverage the page\'s parent does not serve (mis-parent?)', $plan['unreachable'], withCandidates: true);
            $this->surface('NO COVERAGE — name matches no coverage area on this site', $plan['no_coverage'], withCandidates: false);

            if ($execute) {
                $anchored = $anchor->execute($site);
                $this->line("  <fg=green>anchored {$anchored} page(s)</>; {$unresolved} still need a human.");
            }
        }

        if (! $execute && $grandUnresolved > 0) {
            $this->newLine();
            $this->line("Read the {$grandUnresolved} surfaced page(s) above before --execute. Anchoring writes only the unambiguous matches; the surfaced ones stay null until resolved by hand.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function surface(string $title, array $rows, bool $withCandidates): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=yellow>{$title}</> — ".count($rows));
        foreach ($rows as $row) {
            $this->line("    · {$row['title']}  (key: {$row['key']})");
            if ($withCandidates && isset($row['candidates'])) {
                foreach ($row['candidates'] as $c) {
                    $this->line("        ↳ {$c['name']}  [{$c['geo_id']}]");
                }
            }
        }
    }
}
