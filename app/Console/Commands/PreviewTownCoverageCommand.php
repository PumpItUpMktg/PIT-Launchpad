<?php

namespace App\Console\Commands;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\TownCoveragePreview;
use Illuminate\Console\Command;

/**
 * Previews (dry-run by default) the town-page nearest-neighbour coverage change and, with --apply, repushes.
 *
 * The preview is COMPUTED from the real production selector ({@see \App\Publishing\Blocks\ServiceAreaResolver::neighbours()}),
 * not a re-derived proxy — the numbers are what the render will actually produce. It reports, per tenant, the
 * town neighbour-count distribution (6 / 3–5 / 1–2 / dropped, drops split into un-anchored vs nothing-in-range)
 * and the repush count (every published location page). `--apply` dispatches {@see PublishContent} (idempotent,
 * one in-flight per page) for each published location page so the deterministic render replaces the section.
 */
class PreviewTownCoverageCommand extends Command
{
    protected $signature = 'launchpad:preview-town-coverage
        {--site= : Only this site id (default: all sites)}
        {--show : List each town page with its computed neighbours}
        {--apply : Repush every published location page (dispatches PublishContent) — NOT a dry run}';

    protected $description = 'Dry-run the town-page nearest-neighbour coverage (distribution + repush count, computed from the real selector). --apply repushes.';

    public function handle(TownCoveragePreview $preview): int
    {
        if (($siteId = $this->option('site')) !== null) {
            $site = Site::withoutGlobalScopes()->find($siteId);
            if ($site === null) {
                $this->error("No site with id {$siteId}.");

                return self::FAILURE;
            }
            $entry = $preview->forSite($site);
            $sites = $entry['total'] > 0 ? [$entry] : [];
        } else {
            $sites = $preview->report();
        }

        if ($sites === []) {
            $this->info('No published location pages found.');

            return self::SUCCESS;
        }

        $onlyShow = (bool) $this->option('show');
        $totalRepush = 0;
        $totalTownDrop = 0;

        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $totalRepush += $entry['total'];
            $totalTownDrop += $entry['dist']['drop'];

            $this->newLine();
            $this->line("<info>{$entry['brand']}</info>  ({$site->id})");
            $this->line(sprintf(
                '  %d published location page(s) to repush — %d hub, %d town',
                $entry['total'],
                $entry['hub'],
                $entry['town'],
            ));
            $d = $entry['dist'];
            $this->line(sprintf(
                '  town neighbours — 6: %d   3–5: %d   1–2: %d   dropped: %d (%d un-anchored, %d nothing in range)',
                $d['6'],
                $d['3-5'],
                $d['1-2'],
                $d['drop'],
                $entry['unanchored'],
                $entry['no_range'],
            ));

            if ($onlyShow && $entry['pages'] !== []) {
                $rows = [];
                foreach ($entry['pages'] as $p) {
                    $rows[] = [
                        $p['dropped'] ? ($p['anchored'] ? 'drop (no range)' : 'drop (un-anchored)') : (string) $p['count'],
                        $p['slug'],
                        $p['dropped'] ? '—' : implode(', ', $p['neighbours']),
                    ];
                }
                $this->table(['Neighbours', 'Town', 'Names'], $rows);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Portfolio: %d published location page(s) would be repushed; %d town page(s) drop the coverage section.',
            $totalRepush,
            $totalTownDrop,
        ));

        if (! (bool) $this->option('apply')) {
            $this->comment('DRY RUN — nothing pushed. The distribution is computed from the real selector. Re-run with --apply to repush.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($sites as $entry) {
            /** @var Site $site */
            $site = $entry['site'];
            $ids = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('kind', ContentKind::Page->value)
                ->where('page_type', PageType::Location->value)
                ->where('status', ContentStatus::Published->value)
                ->pluck('id');
            foreach ($ids as $id) {
                PublishContent::dispatch((string) $id);
                $dispatched++;
            }
        }

        $this->newLine();
        $this->info("Repush dispatched: {$dispatched} PublishContent job(s) (idempotent, one in-flight per page).");

        return self::SUCCESS;
    }
}
