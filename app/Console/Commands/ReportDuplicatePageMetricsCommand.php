<?php

namespace App\Console\Commands;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\Coverage\DuplicatePageMetrics;
use Illuminate\Console\Command;

/**
 * Report (read-only): GSC impressions + blended position for BOTH sides of every live duplicate location
 * pair — the data the operator needs to decide, per pair, whether a canonical or a 301 is right and in
 * which direction. Covers the landing↔town pairs ("/hoboken-nj/" vs "/hoboken-nj/hoboken-nj/") and the
 * town↔town duplicates (the "buckingham-pa" / "buckingham-pa-2" shape) — see {@see DuplicatePageMetrics}.
 *
 * The member carrying the impressions is the ranking page (marked ← earns). When a market landing is
 * trimmed to a GBP location card, authority must flow to the earner — a self-canonical on the gutted side
 * throws it away — so this report settles the direction before any redirect/canonical is written.
 *
 * READ-ONLY, all tenants (or one via --site); --days sets the GSC window (default 28). Requires GSC to have
 * been synced (launchpad:sync-gsc) — run this where the synced rows live.
 */
class ReportDuplicatePageMetricsCommand extends Command
{
    protected $signature = 'launchpad:report-duplicate-page-metrics
        {--site= : Limit to one site id or brand name}
        {--days=28 : GSC window in days}';

    protected $description = 'Report (read-only) GSC impressions + position for both sides of every live duplicate location pair (landing↔town and town↔town).';

    public function handle(DuplicatePageMetrics $metrics): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }

        $days = max(1, (int) $this->option('days'));
        $this->info("Read-only · live duplicate location pairs with GSC impressions + blended position (last {$days}d).");

        $grandPairs = 0;
        foreach ($sites as $site) {
            $groups = $metrics->report($site, $days);
            if ($groups === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            foreach ($groups as $g) {
                $grandPairs++;
                $this->line("  <comment>{$g['town']}</comment> — ".count($g['members']).' live pages:');
                foreach ($g['members'] as $m) {
                    $pos = $m['position'] !== null ? (string) $m['position'] : '—';
                    $earns = $m['top_impressions'] && $m['impressions'] > 0 ? ' <fg=green>← earns</>' : '';
                    $this->line(sprintf(
                        '      [%-7s] %s · impr %d · clicks %d · pos %s · index: %s%s',
                        $m['role'],
                        $m['url'] ?? '(no domain)',
                        $m['impressions'],
                        $m['clicks'],
                        $pos,
                        $m['index'],
                        $earns,
                    ));
                }
            }
        }

        $this->newLine();
        if ($grandPairs === 0) {
            $this->info('No live duplicate location pairs found.');

            return self::SUCCESS;
        }

        $this->line("<info>{$grandPairs}</info> live duplicate group(s). Per pair: keep the earner as the canonical page; "
            .'point the other at it (301 if the loser has its own equity to pass, canonical if not). When a landing is '
            .'trimmed to a location card, the town page should be the canonical — confirm it is the earner here first.');

        return self::SUCCESS;
    }
}
