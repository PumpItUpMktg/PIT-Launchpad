<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Jobs\RunTownRankSweep;
use App\Models\Site;
use App\Support\SiteFinder;
use App\TownRank\TownRankSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The weekly town-rank sweep driver (§ Town Rank, PR 3): for every engine-eligible site (or one site), plan
 * the due (keyword × mode) pairs and dispatch one {@see RunTownRankSweep} job per site that has work and is
 * under its ceiling. Report-first: --dry-run prints the plan and dispatches nothing. Scheduled weekly.
 */
class TownRankSweepCommand extends Command
{
    /** Sites the engine runs for — past onboarding, not suspended. */
    private const ELIGIBLE = [SiteStatus::Active, SiteStatus::Building, SiteStatus::Live];

    protected $signature = 'launchpad:town-rank-sweep
        {--site= : One site (id, brand name, or domain) instead of every eligible site}
        {--dry-run : Print the plan per site and dispatch nothing}';

    protected $description = 'Dispatch the due town-rank scans (keyword × mode past cadence) per eligible site; --dry-run plans only.';

    public function handle(TownRankSweep $sweep): int
    {
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $dispatched = 0;
        foreach ($sites as $site) {
            $plan = $sweep->plan($site);
            if ($plan['due'] === []) {
                continue;
            }
            $pairs = implode(', ', array_map(fn (array $p): string => $p['keyword']->query.' · '.$p['mode'], $plan['due']));
            $this->line(sprintf(
                '%s — %d town(s) × %d due pair(s) = %s request(s) (~$%s)%s',
                $site->brand_name ?: (string) $site->id, $plan['towns'], count($plan['due']), number_format($plan['requests']), number_format($plan['cost'], 2),
                $plan['over_ceiling'] ? " — <error>over the ceiling ({$plan['ceiling']}), skipped</error>" : '',
            ));
            $this->line("    {$pairs}");
            if ($plan['over_ceiling'] || $plan['towns'] === 0 || $dryRun) {
                continue;
            }
            RunTownRankSweep::dispatch((string) $site->id);
            $dispatched++;
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run — nothing dispatched.' : "Dispatched {$dispatched} site sweep(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, Site>|null */
    private function sites(): ?Collection
    {
        $opt = trim((string) $this->option('site'));
        if ($opt === '') {
            return Site::withoutGlobalScopes()
                ->whereIn('status', array_map(fn (SiteStatus $s): string => $s->value, self::ELIGIBLE))
                ->orderBy('brand_name')
                ->get();
        }
        $matches = SiteFinder::matches($opt);
        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty() ? "No site matches [{$opt}]." : "[{$opt}] is ambiguous — re-run with the id.");

            return null;
        }

        /** @var Collection<int, Site> $matches */
        return $matches;
    }
}
