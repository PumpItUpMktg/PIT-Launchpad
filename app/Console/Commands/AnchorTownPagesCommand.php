<?php

namespace App\Console\Commands;

use App\Locations\TownPageGeoAnchor;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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

    /** @param  Collection<int, Site>  $sites */
    private function listSites(Collection $sites): void
    {
        foreach ($sites as $site) {
            $this->line(sprintf('  · %s — %s (%s)', $site->brand_name, $site->domain_url ?? 'no domain', $site->id));
        }
    }

    public function handle(TownPageGeoAnchor $anchor): int
    {
        // The same forgiving lookup every other launchpad command takes: an id, a brand name, a domain,
        // or part of one. It used to demand an EXACT id or brand name and answer "No site matches [sump]"
        // with no hint that "Sump Pump Gurus" was sitting right there — a dead end you can only escape by
        // already knowing the answer.
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $matches = SiteFinder::matches($opt);
            if ($matches->isEmpty()) {
                $this->error("No site matches [{$opt}]. Available sites:");
                $this->listSites(SiteFinder::all());

                return self::FAILURE;
            }
            if ($matches->count() > 1) {
                $this->error("[{$opt}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id or exact name:");
                $this->listSites($matches);

                return self::FAILURE;
            }
            $sites = $matches;
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
