<?php

namespace App\Console\Commands;

use App\Analytics\Gsc\GscBackfill;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-time recovery of Search Console history into the daily time-series store
 * (Stage 2). Pulls the full available window (~16 months; GSC keeps no more) for
 * every connected property — anything older is gone as the window rolls, so this
 * is the time-sensitive half of the relay. Idempotent, so it is safe to re-run.
 *
 * Reports, per property: the requested window, how far back data was ACTUALLY
 * available (a recently-verified property returns less), and the rows recovered.
 */
class BackfillGscCommand extends Command
{
    protected $signature = 'launchpad:backfill-gsc {--site= : Site id or brand name (all GSC-connected sites if omitted)} {--months= : Window depth in months (default config, ~16)}';

    protected $description = 'One-time backfill of the full ~16-month Search Console window into the daily time-series store.';

    public function handle(GscBackfill $backfill): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }
        if ($sites->isEmpty()) {
            $this->warn('No GSC-connected sites (none has a gsc_property picked).');

            return self::SUCCESS;
        }

        $months = $this->option('months') !== null ? max(1, (int) $this->option('months')) : null;

        foreach ($sites as $site) {
            $r = $backfill->run($site, $months);

            $this->line("<info>{$site->brand_name}</info> ({$site->gsc_property})");
            $this->line(sprintf('  recovered %d url-daily + %d url×query rows.', $r['url_daily'], $r['url_query_daily']));
            $this->line(sprintf('  window requested from %s; earliest actually available: %s (latest %s).',
                $r['requested_start'], $r['earliest_available'] ?? '— none returned', $r['latest'] ?? '—'));

            if ($r['earliest_available'] === null) {
                $this->warn('  Nothing returned — property not connected, empty, or newly verified.');
            } elseif ($r['earliest_available'] > $r['requested_start']) {
                $this->warn("  Less history than requested — GSC has data only back to {$r['earliest_available']} for this property.");
            }

            if ($r['rolled_months'] > 0) {
                $this->line(sprintf('  rolled %d month(s) to the monthly table, pruned %d daily row(s).', $r['rolled_months'], $r['daily_pruned']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>|null null on an unresolvable --site
     */
    private function resolveSites(): ?Collection
    {
        $arg = trim((string) $this->option('site'));

        if ($arg !== '') {
            $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
            if ($site === null) {
                $this->error("No site matches [{$arg}].");

                return null;
            }

            return collect([$site]);
        }

        return Site::query()
            ->whereNotNull('gsc_property')
            ->where('gsc_property', '!=', '')
            ->get();
    }
}
