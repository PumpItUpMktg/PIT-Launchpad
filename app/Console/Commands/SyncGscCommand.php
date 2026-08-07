<?php

namespace App\Console\Commands;

use App\Analytics\Gsc\GscRollup;
use App\Analytics\Gsc\GscSnapshotIngestor;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Snapshot Search Console into the never-overwritten daily store (Stage 1
 * ingest + rollup). Pulls a short trailing window per site — absorbing GSC's
 * ~3-day revisions idempotently — then rolls query-grain rows past the
 * retention window into the monthly table. Scheduled daily; also runnable ad
 * hoc for one tenant.
 *
 * This is the steady-state beat. The one-time ~16-month history recovery is
 * {@see BackfillGscCommand} (launchpad:backfill-gsc).
 */
class SyncGscCommand extends Command
{
    protected $signature = 'launchpad:sync-gsc {--site= : Site id or brand name (all GSC-connected sites if omitted)}';

    protected $description = 'Snapshot Search Console into the daily time-series store (trailing-window ingest + monthly rollup).';

    public function handle(GscSnapshotIngestor $ingestor, GscRollup $rollup): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }
        if ($sites->isEmpty()) {
            $this->warn('No GSC-connected sites (none has a gsc_property picked).');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $pulled = $ingestor->sync($site);
            $rolled = $rollup->run($site);

            $this->line(sprintf(
                '<info>%s</info> — url-daily +%d, url×query +%d (%s…%s); rolled %d month(s), pruned %d daily row(s).',
                $site->brand_name ?? $site->id,
                $pulled['url_daily'],
                $pulled['url_query_daily'],
                $pulled['earliest_date'] ?? '—',
                $pulled['latest_date'] ?? '—',
                $rolled['months'],
                $rolled['daily_pruned'],
            ));
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
