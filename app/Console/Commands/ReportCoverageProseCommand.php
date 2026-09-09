<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\CoverageProseReport;
use Illuminate\Console\Command;

/**
 * READ-ONLY census of the location-page "coverage prose" section (`loc_coverage`, rendered as "The towns we
 * cover around {city}") across the portfolio, or one site with `--site=`. Changes nothing. It reports, split
 * by HUB vs TOWN page, how many published location pages exist (the repush count once the section is
 * replaced), how many carry the drafted enumeration today, and how long it runs — so the scope of the fix is
 * known before any page is repushed. `--long` lists only the pages whose coverage slot is long enough to be
 * a keyword dump.
 */
class ReportCoverageProseCommand extends Command
{
    protected $signature = 'launchpad:report-coverage-prose
        {--site= : Report only this site id (default: all sites)}
        {--long : List only the pages whose coverage prose runs long (likely a keyword dump)}';

    protected $description = 'READ-ONLY: census of location-page coverage prose (loc_coverage) — repush scope + keyword-dump count. Changes nothing.';

    public function handle(CoverageProseReport $report): int
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

        $onlyLong = (bool) $this->option('long');
        $totalPages = 0;
        $totalProse = 0;
        $totalLong = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $totalPages += $entry['total'];
            $totalProse += $entry['with_prose'];
            $totalLong += $entry['long'];

            $this->newLine();
            $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
            $this->line(sprintf(
                '  %d published location page(s) — %d hub, %d town · %d carry coverage prose · %d run long (>%d chars) · longest %d',
                $entry['total'],
                $entry['hub'],
                $entry['town'],
                $entry['with_prose'],
                $entry['long'],
                CoverageProseReport::LONG_CHARS,
                $entry['max'],
            ));

            $pages = $onlyLong
                ? array_values(array_filter($entry['pages'], fn (array $p): bool => $p['long']))
                : array_values(array_filter($entry['pages'], fn (array $p): bool => $p['has_prose']));

            if ($pages === []) {
                continue;
            }

            $rows = [];
            foreach ($pages as $p) {
                $rows[] = [
                    $p['chars'].($p['long'] ? ' ⚠' : ''),
                    $p['kind'],
                    $p['slug'],
                ];
            }
            $this->table(['Chars', 'Type', 'Slug'], $rows);
        }

        $this->newLine();
        $this->line(sprintf(
            'Portfolio: %d published location page(s) would be repushed; %d carry coverage prose today, of which %d run long (likely keyword dumps).',
            $totalPages,
            $totalProse,
            $totalLong,
        ));
        $this->comment('READ-ONLY — nothing was changed. This is the repush-scope measurement before the coverage section is replaced.');

        return self::SUCCESS;
    }
}
