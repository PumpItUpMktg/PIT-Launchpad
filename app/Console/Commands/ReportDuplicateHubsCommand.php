<?php

namespace App\Console\Commands;

use App\Build\DuplicateHubReport;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * READ-ONLY report of duplicate "hub" pages across the portfolio (or one site with `--site=`). Changes
 * nothing — the "report before deleting" pass that must run and be reviewed before any hub cleanup
 * ({@see DuplicateHubReport}). Covers all three hub-shaped duplications so a clean result is airtight:
 * silo hubs (by silo), orphan hubs (`page_type=Hub` with no silo, by title), and location landings (the
 * other "hub": `page_type=Location` with a `location_id`, by location). For each duplicated group it prints
 * the keeper, removable vs blocked extras, and how many child pages a cleanup would re-point.
 */
class ReportDuplicateHubsCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-hubs {--site= : Report only this site id (default: all sites)}';

    protected $description = 'READ-ONLY: report duplicate hub pages (silo hubs, orphan hubs, location landings). Deletes nothing.';

    public function handle(DuplicateHubReport $report): int
    {
        if (($siteId = $this->option('site')) !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $sites = array_filter([
                (string) $site->id => [
                    'site' => $site,
                    'silo_hubs' => $report->forSite($site),
                    'orphan_hubs' => $report->orphanHubs($site),
                    'location_landings' => $report->locationLandings($site),
                ],
            ], fn (array $r): bool => $r['silo_hubs'] !== [] || $r['orphan_hubs'] !== [] || $r['location_landings'] !== []);
        } else {
            $sites = $report->report();
        }

        if ($sites === []) {
            $this->info('No duplicate hubs found — silo hubs, orphan hubs, and location landings are all clean.');

            return self::SUCCESS;
        }

        $totals = ['silo_hubs' => 0, 'orphan_hubs' => 0, 'location_landings' => 0, 'removable' => 0, 'blocked' => 0, 'children' => 0];

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $this->newLine();
            $this->line("<info>{$site->brand_name}</info>  ({$site->id})");

            $this->section('Silo hubs (by silo)', $entry['silo_hubs'], $totals);
            $this->section('Orphan hubs — page_type=Hub, no silo (by title)', $entry['orphan_hubs'], $totals);
            $this->section('Location landings — page_type=Location + location_id (by location)', $entry['location_landings'], $totals);
        }

        $this->newLine();
        $this->line(sprintf(
            'Total duplicated groups: %d silo hub(s), %d orphan-hub group(s), %d location landing(s) across %d site(s).',
            $totals['silo_hubs'],
            $totals['orphan_hubs'],
            $totals['location_landings'],
            count($sites),
        ));
        $this->line(sprintf(
            '  → %d removable extra(s), %d blocked (manual), %d child page(s) would be re-pointed.',
            $totals['removable'],
            $totals['blocked'],
            $totals['children'],
        ));
        $this->comment('READ-ONLY — nothing was changed. This is the verification pass before any hub cleanup.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{key: string, label: string, total: int, keeper: array{id: string, slug: string, status: string}, removable: list<mixed>, blocked: list<mixed>, children_to_repoint: int}>  $groups
     * @param  array<string, int>  $totals
     */
    private function section(string $heading, array $groups, array &$totals): void
    {
        if ($groups === []) {
            return;
        }

        $bucket = match (true) {
            str_starts_with($heading, 'Silo') => 'silo_hubs',
            str_starts_with($heading, 'Orphan') => 'orphan_hubs',
            default => 'location_landings',
        };

        $rows = [];
        foreach ($groups as $g) {
            $totals[$bucket]++;
            $totals['removable'] += count($g['removable']);
            $totals['blocked'] += count($g['blocked']);
            $totals['children'] += $g['children_to_repoint'];
            $rows[] = [
                $g['label'],
                $g['total'],
                "{$g['keeper']['slug']} ({$g['keeper']['status']})",
                (string) count($g['removable']),
                (string) count($g['blocked']),
                (string) $g['children_to_repoint'],
            ];
        }

        $this->line("  <comment>{$heading}</comment>");
        $this->table(['Group', 'Pages', 'Keeper', 'Removable', 'Blocked', 'Children→repoint'], $rows);
    }
}
