<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Publishing\LocationBodyReport;
use Illuminate\Console\Command;

/**
 * READ-ONLY report scoping the REGENERATE set: anchored location pages whose DRAFTED content (hero H1 +
 * body slots) names the wrong town — the hallucination the deterministic-title fix could not reach, because
 * a repush re-renders drafted slots as-is. `foreign_town` (a wrong "{City}, {ST}" in the H1) is the
 * high-precision regenerate set; `weak` is a region/town-blind H1; `ok` names the right town. Changes nothing.
 */
class ReportLocationBodyCommand extends Command
{
    protected $signature = 'launchpad:report-location-body
        {--site= : Report only this site id (default: all sites)}
        {--foreign : List only the foreign-town pages (the regenerate set)}';

    protected $description = 'READ-ONLY: anchored location pages whose drafted content names the wrong town (regenerate scope). Changes nothing.';

    public function handle(LocationBodyReport $report): int
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

        $onlyForeign = (bool) $this->option('foreign');
        $totalForeign = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $totalForeign += $entry['foreign_town'];

            $this->newLine();
            $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
            $this->line(sprintf(
                '  %d anchored location page(s) · %d foreign-town (regenerate) · %d town-blind H1 · %d ok',
                $entry['anchored'],
                $entry['foreign_town'],
                $entry['weak'],
                $entry['ok'],
            ));

            $pages = $onlyForeign
                ? array_values(array_filter($entry['pages'], fn (array $p): bool => $p['status'] === 'foreign_town'))
                : array_values(array_filter($entry['pages'], fn (array $p): bool => $p['status'] !== 'ok'));

            if ($pages === []) {
                continue;
            }

            $rows = [];
            foreach ($pages as $p) {
                $rows[] = [
                    $p['status'] === 'foreign_town' ? 'FOREIGN' : 'weak',
                    $p['slug'],
                    $p['auth'],
                    $p['foreign'] === [] ? '—' : implode(', ', $p['foreign']),
                    $p['h1'],
                ];
            }
            $this->table(['Flag', 'Slug', 'Authoritative', 'Foreign town(s) in body', 'Drafted H1'], $rows);
        }

        $this->newLine();
        $this->line(sprintf('Portfolio: %d anchored location page(s) name a wrong town in their drafted H1 — the regenerate set.', $totalForeign));
        $this->comment('READ-ONLY — nothing changed. Regenerate these pages (now correctly anchored) to purge the hallucinated town from the drafted content.');

        return self::SUCCESS;
    }
}
