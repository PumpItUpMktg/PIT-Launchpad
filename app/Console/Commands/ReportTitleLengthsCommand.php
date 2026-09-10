<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\TitleLengthReport;
use Illuminate\Console\Command;

/**
 * READ-ONLY report of the REAL rendered `<title>` length across the portfolio (or one site with `--site=`).
 * Changes nothing. It measures the actual composed title (MetaBlobAssembler::documentTitle — normalize +
 * service/hub qualifier + brand suffix AND the length guard), the same value the `<title>`/og:title ship —
 * NOT a `page + " | brand"` projection. So `over` is the TRUE count of published titles that exceed 60
 * characters as they render live. `--over` lists only those.
 */
class ReportTitleLengthsCommand extends Command
{
    protected $signature = 'launchpad:report-title-lengths
        {--site= : Report only this site id (default: all sites)}
        {--over : List only the pages whose composed title exceeds 60 characters}';

    protected $description = 'READ-ONLY: report the real composed <title> length per published page (guard applied). Changes nothing.';

    public function handle(TitleLengthReport $report): int
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
            $this->info('No published pages found.');

            return self::SUCCESS;
        }

        $onlyOver = (bool) $this->option('over');
        $totalPages = 0;
        $totalOver = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $totalPages += $entry['total'];
            $totalOver += $entry['over'];

            $this->newLine();
            $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
            $this->line(sprintf(
                '  %d published page(s) · %d composed <title>(s) over %d · longest %d',
                $entry['total'],
                $entry['over'],
                TitleLengthReport::LIMIT,
                $entry['max'],
            ));

            // Coarse histogram of composed-title lengths.
            $hist = [];
            foreach ($entry['buckets'] as $range => $count) {
                $hist[] = "{$range}: {$count}";
            }
            $this->line('  composed-title distribution — '.implode('  ', $hist));

            $pages = $onlyOver
                ? array_values(array_filter($entry['pages'], fn (array $p): bool => $p['over']))
                : $entry['pages'];

            if ($pages === []) {
                continue;
            }

            $rows = [];
            foreach ($pages as $p) {
                $rows[] = [
                    $p['len'].($p['over'] ? ' ⚠' : ''),
                    $p['page_type'],
                    $p['slug'],
                    $p['title'],
                ];
            }
            $this->table(['Len', 'Type', 'Slug', 'Composed <title> (as it renders)'], $rows);
        }

        $this->newLine();
        $this->line(sprintf(
            'Portfolio: %d of %d published page(s) render a <title> over %d characters (the real composed value, guard applied).',
            $totalOver,
            $totalPages,
            TitleLengthReport::LIMIT,
        ));
        $this->comment('READ-ONLY — nothing was changed. This is the true over-length count as titles render live.');

        return self::SUCCESS;
    }
}
