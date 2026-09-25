<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\StuckPages;
use Illuminate\Console\Command;

/**
 * The stuck-pages report: every published page past the stuck window and still not indexed, with
 * Google's reason, how many pages link to it, and the one lever that moves it — grouped by lever so
 * the operator runs the link plan for the markets that need it and pings the rest in one pass.
 * Read-only.
 */
class ReportStuckPagesCommand extends Command
{
    protected $signature = 'launchpad:report-stuck-pages {--site= : Site id or brand name}';

    protected $description = 'List published pages not indexed past the stuck window, with Google\'s reason, inbound links, and the lever per page';

    public function handle(StuckPages $stuck): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Pass --site= with a site id or brand name.');

            return self::FAILURE;
        }

        $report = $stuck->for($site);
        $this->line("<info>{$site->brand_name}</info> — ".count($report['rows'])." page(s) not indexed after {$report['stuck_days']}+ days");

        if ($report['rows'] === []) {
            $this->info('Nothing stuck.');

            return self::SUCCESS;
        }

        $labels = [
            StuckPages::LINK => 'LINK IT — no inbound links',
            StuckPages::PING => 'PING — linked, not crawled yet',
            StuckPages::REGENERATE => 'REGENERATE — crawled, linked, still not indexed',
            StuckPages::RECHECK => 'RE-CHECK — no usable verdict',
            StuckPages::UNBLOCK => 'UNBLOCK — noindex / blocked on WordPress',
        ];

        $lastLever = null;
        foreach ($report['rows'] as $row) {
            if ($row['lever'] !== $lastLever) {
                $this->newLine();
                $this->line('<comment>'.($labels[$row['lever']] ?? $row['lever']).'</comment> ('.$report['by_lever'][$row['lever']].')');
                $lastLever = $row['lever'];
            }
            $this->line(sprintf(
                '  %-40s %3dd  %-28s links:%-2d  indexnow:%s  %s',
                mb_strimwidth($row['title'], 0, 40, '…'),
                $row['days_waiting'] ?? 0,
                mb_strimwidth($row['reason'], 0, 28, '…'),
                $row['inbound'],
                $row['indexnow_at'] ?? 'never',
                $row['url'] ?? '',
            ));
        }

        $this->newLine();
        $this->line('<comment>Next</comment>');
        if ($report['markets_needing_links'] !== []) {
            foreach ($report['markets_needing_links'] as $marketId) {
                $this->line("  php artisan launchpad:plan-links {$site->id} --market={$marketId}   (add --tier= for the band; omit for ungrouped)");
            }
        }
        if (isset($report['by_lever'][StuckPages::PING]) || isset($report['by_lever'][StuckPages::LINK])) {
            $this->line("  php artisan launchpad:indexnow --site={$site->id}");
            $this->line("  php artisan launchpad:submit-sitemap --site={$site->id}");
        }
        if (isset($report['by_lever'][StuckPages::REGENERATE])) {
            $this->line('  Regenerate the REGENERATE rows from the Pages board once their local proof is in, then "Re-check indexing".');
        }
        if (isset($report['by_lever'][StuckPages::RECHECK])) {
            $this->line('  Results → Indexing → "Re-check indexing now" for the RE-CHECK rows.');
        }

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $id = $this->option('site');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->find($id) ?? Site::query()->where('brand_name', $id)->first();
    }
}
