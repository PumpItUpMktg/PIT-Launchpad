<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\TitleLengthReport;
use Illuminate\Console\Command;

/**
 * READ-ONLY report of rendered page-title lengths across the portfolio (or one site with `--site=`). Changes
 * nothing. It measures the page portion of each published page's title — normalized, plus the service-area
 * region on service/hub pages — i.e. the value BEFORE any brand suffix is composed on, and the projected
 * length once the brand suffix is added.
 *
 * The finding it surfaces: the page portion can never exceed 60 (normalize + the region qualifier both cap
 * at 60), so the literal "titles over 60 before the suffix" count is 0 by construction. The number that
 * decides whether shortening is needed is HEADROOM — how many titles have no room for the brand suffix
 * (`page portion + " | brand" > 60`). `--over` lists only those.
 */
class ReportTitleLengthsCommand extends Command
{
    protected $signature = 'launchpad:report-title-lengths
        {--site= : Report only this site id (default: all sites)}
        {--over : List only the pages with no headroom for the brand suffix}';

    protected $description = 'READ-ONLY: report published page-title lengths (page portion + projected brand-suffix fit). Changes nothing.';

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
        $totalBrandOver = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $totalPages += $entry['total'];
            $totalOver += $entry['over'];
            $totalBrandOver += $entry['brand_over'];

            $this->newLine();
            $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
            $this->line(sprintf(
                '  %d published page(s) · %d page portion(s) over %d · %d with NO room for the brand suffix · longest portion %d · suffix costs %d char(s)',
                $entry['total'],
                $entry['over'],
                TitleLengthReport::LIMIT,
                $entry['brand_over'],
                $entry['max'],
                $entry['brand_cost'],
            ));

            // Coarse histogram of page-portion lengths.
            $hist = [];
            foreach ($entry['buckets'] as $range => $count) {
                $hist[] = "{$range}: {$count}";
            }
            $this->line('  page-portion distribution — '.implode('  ', $hist));

            $pages = $onlyOver
                ? array_values(array_filter($entry['pages'], fn (array $p): bool => $p['brand_over']))
                : $entry['pages'];

            if ($pages === []) {
                continue;
            }

            $rows = [];
            foreach ($pages as $p) {
                $rows[] = [
                    $p['len'].($p['over'] ? ' ⚠' : ''),
                    $p['with_brand'].($p['brand_over'] ? ' ⚠' : ''),
                    $p['page_type'],
                    $p['slug'],
                    $p['title'],
                ];
            }
            $this->table(['Portion', '+Brand', 'Type', 'Slug', 'Rendered title (before brand)'], $rows);
        }

        $this->newLine();
        $this->line(sprintf(
            'Portfolio: %d of %d published page(s) have a page portion over %d BEFORE any suffix (0 expected — normalize + the region qualifier cap at %d).',
            $totalOver,
            $totalPages,
            TitleLengthReport::LIMIT,
            TitleLengthReport::LIMIT,
        ));
        $this->line(sprintf(
            '           %d of %d have NO room for the brand suffix (page portion + " | brand" > %d) — the guard drops a subtitle where it can, else leaves the title over-length.',
            $totalBrandOver,
            $totalPages,
            TitleLengthReport::LIMIT,
        ));
        $this->comment('READ-ONLY — nothing was changed. This is the corpus measurement before deciding any title shortening.');

        return self::SUCCESS;
    }
}
