<?php

namespace App\Console\Commands;

use App\Build\DuplicateHubReport;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * READ-ONLY report of duplicate silo-hub pages across the portfolio (or one site with `--site=`). Changes
 * nothing — it is the "report before deleting" pass that must run and be reviewed before any hub cleanup
 * ({@see DuplicateHubReport}). For each silo carrying more than one Hub page it prints the keeper,
 * how many extras are safely removable (empty + unpublished) vs blocked (published/drafted — need manual
 * review), and how many child pages are parented to a non-keeper hub (a cleanup would re-point these).
 */
class ReportDuplicateHubsCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-hubs {--site= : Report only this site id (default: all sites)}';

    protected $description = 'READ-ONLY: report duplicate silo-hub pages (keeper / removable / blocked / children to re-point). Deletes nothing.';

    public function handle(DuplicateHubReport $report): int
    {
        if (($siteId = $this->option('site')) !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $sites = [(string) $site->id => ['site' => $site, 'groups' => $report->forSite($site)]];
            $sites = array_filter($sites, fn (array $r): bool => $r['groups'] !== []);
        } else {
            $sites = $report->report();
        }

        if ($sites === []) {
            $this->info('No duplicate silo hubs found — every silo has at most one hub page.');

            return self::SUCCESS;
        }

        $totalGroups = 0;
        $totalRemovable = 0;
        $totalBlocked = 0;
        $totalChildren = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $this->newLine();
            $this->line("<info>{$site->brand_name}</info>  ({$site->id})");

            $rows = [];
            foreach ($entry['groups'] as $g) {
                $totalGroups++;
                $totalRemovable += count($g['removable']);
                $totalBlocked += count($g['blocked']);
                $totalChildren += $g['children_to_repoint'];

                $rows[] = [
                    $g['silo_name'],
                    $g['total'],
                    "{$g['keeper']['slug']} ({$g['keeper']['status']})",
                    (string) count($g['removable']),
                    (string) count($g['blocked']),
                    (string) $g['children_to_repoint'],
                ];
            }

            $this->table(['Silo', 'Hubs', 'Keeper', 'Removable', 'Blocked', 'Children→repoint'], $rows);
        }

        $this->newLine();
        $this->line(sprintf(
            'Total: %d duplicated silo(s) across %d site(s) — %d removable extra(s), %d blocked (manual), %d child page(s) would be re-pointed.',
            $totalGroups,
            count($sites),
            $totalRemovable,
            $totalBlocked,
            $totalChildren,
        ));
        $this->comment('READ-ONLY — nothing was changed. This is the verification pass before any hub cleanup.');

        return self::SUCCESS;
    }
}
