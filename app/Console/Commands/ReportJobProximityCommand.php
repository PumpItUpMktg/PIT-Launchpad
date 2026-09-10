<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\JobProximityReport;
use Illuminate\Console\Command;

/**
 * READ-ONLY: the job distribution a proximity-scoped "Recent jobs near {town}" section would produce, per
 * tenant — the read-before-shipping data for the jobs fix (PR 5). Changes nothing. It measures each
 * published location page's jobs from the right subject (a town from its own centroid, a hub from the
 * location's coordinates) against the site's published jobs, with the same distance + radius the
 * town-coverage change uses — so the numbers are what the fix will actually select, not a proxy. The
 * distribution (3+ / 1–2 / dropped) tells us whether the radius is right before the render change ships.
 */
class ReportJobProximityCommand extends Command
{
    protected $signature = 'launchpad:report-job-proximity
        {--site= : Report only this site id (default: all sites)}
        {--show : List each page with its in-range job count}';

    protected $description = 'READ-ONLY: the "recent jobs near {town}" distribution under the proximity rule (validate the radius before shipping). Changes nothing.';

    public function handle(JobProximityReport $report): int
    {
        if (($siteId = $this->option('site')) !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $entry = $report->forSite($site);
            $sites = $entry['total'] > 0 ? [$entry] : [];
        } else {
            $sites = $report->report();
        }

        if ($sites === []) {
            $this->info('No published location pages found.');

            return self::SUCCESS;
        }

        $onlyShow = (bool) $this->option('show');
        $totalPages = 0;
        $totalDrop = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $totalPages += $entry['total'];
            $totalDrop += $entry['dist']['drop'];

            $this->newLine();
            $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
            $this->line(sprintf(
                '  %d published location page(s) · %d published job(s) with coords · radius %.0f mi',
                $entry['total'],
                $entry['jobs'],
                $entry['radius'],
            ));
            $d = $entry['dist'];
            $this->line(sprintf(
                '  jobs in range — 3+: %d   1–2: %d   dropped: %d (%d have no measurable subject)',
                $d['n3'],
                $d['n1_2'],
                $d['drop'],
                $entry['no_subject'],
            ));

            if ($onlyShow && $entry['pages'] !== []) {
                $rows = [];
                foreach ($entry['pages'] as $p) {
                    $rows[] = [
                        $p['dropped'] ? 'drop' : (string) min($p['count'], 3).($p['count'] > 3 ? '  (of '.$p['count'].')' : ''),
                        $p['kind'],
                        $p['slug'],
                    ];
                }
                $this->table(['Jobs', 'Type', 'Page'], $rows);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Portfolio: %d of %d published location page(s) would drop the jobs section (nothing in range).',
            $totalDrop,
            $totalPages,
        ));
        $this->comment('READ-ONLY — nothing changed. This validates the radius before the jobs render change ships.');

        return self::SUCCESS;
    }
}
