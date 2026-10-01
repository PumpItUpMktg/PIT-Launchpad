<?php

namespace App\Console\Commands;

use App\Activity\MonthlySnapshots;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Freeze a site's monthly activity snapshots on demand: every missing closed month (the default), or one
 * month, rebuilt if asked. The scheduled job does the same on the first of each month.
 */
class ActivitySnapshotCommand extends Command
{
    protected $signature = 'launchpad:activity-snapshot
        {--site= : Site id or brand name (all sites when omitted)}
        {--month= : one month (Y-m) instead of every missing month}
        {--rebuild : with --month, recompute an already-closed month}';

    protected $description = 'Freeze the monthly activity snapshots (every missing closed month, or one month)';

    public function handle(MonthlySnapshots $snapshots): int
    {
        $arg = $this->option('site');
        $sites = is_string($arg) && $arg !== ''
            ? Site::withoutGlobalScopes()->where('id', $arg)->orWhere('brand_name', $arg)->get()
            : Site::withoutGlobalScopes()->get();
        if ($sites->isEmpty()) {
            $this->error('No site matched.');

            return self::FAILURE;
        }
        $month = $this->option('month');
        foreach ($sites as $site) {
            if (is_string($month) && $month !== '') {
                $snap = $snapshots->close($site, Carbon::createFromFormat('!Y-m', $month) ?: Carbon::now(), (bool) $this->option('rebuild'));
                $this->line(sprintf('%s · %s: %s', $site->brand_name, $month, $snap === null ? 'not closed yet (the month is still open)' : 'frozen · '.$snap->timeline_entries.' entries'));

                continue;
            }
            $written = $snapshots->backfill($site);
            $this->line(sprintf('%s: %s', $site->brand_name, $written === [] ? 'every closed month already frozen' : 'frozen '.implode(', ', $written)));
        }

        return self::SUCCESS;
    }
}
