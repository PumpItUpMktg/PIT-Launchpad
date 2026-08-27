<?php

namespace App\Console\Commands;

use App\GeoGrid\GeoGridMetrics;
use App\Models\GeoGridScan;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Recompute a site's geo-grid aggregates (found_rate / ARP / ATRP / SoLV) from the stored points — no
 * rescan, no API. This is the cheap half of the §6 calibration loop: because the raw points are the source
 * of truth, correcting a formula (the likely divergence from Local Falcon) is a recompute, not a re-scan.
 * Also re-trends ATRP into metric_snapshots.
 */
class GeoGridRecomputeCommand extends Command
{
    protected $signature = 'launchpad:geo-grid-recompute {site : Site id, brand name, or domain (partial ok)}
        {--scan= : Recompute only this scan id}';

    protected $description = 'Recompute geo-grid aggregates (found_rate/ARP/ATRP/SoLV) from stored points — no rescan.';

    public function handle(GeoGridMetrics $metrics): int
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return self::FAILURE;
        }

        /** @var Site $site */
        $site = $matches->first();

        $scans = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->when($this->option('scan'), fn ($q, $v) => $q->whereKey($v))
            ->get();

        if ($scans->isEmpty()) {
            $this->comment('No geo-grid scans to recompute.');

            return self::SUCCESS;
        }

        foreach ($scans as $scan) {
            $metrics->recompute($scan);
            $this->line(sprintf(
                '  <info>%s</info>  ATRP %s · ARP %s · SoLV %s%% · found %s%%',
                $scan->id,
                $scan->atrp ?? '—', $scan->arp ?? '—', $scan->solv ?? '—', $scan->found_rate ?? '—',
            ));
        }

        $this->newLine();
        $this->info("Recomputed {$scans->count()} scan(s) from stored points. ATRP re-trended into metric_snapshots.");

        return self::SUCCESS;
    }
}
