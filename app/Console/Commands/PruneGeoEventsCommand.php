<?php

namespace App\Console\Commands;

use App\Models\GeoCheckEvent;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prune GEO check activity-log rows past the retention window (config `launchpad.geo.events.retention_days`).
 * The log is append-only and one row per (prompt × engine) step, so it grows fast; this keeps it bounded.
 * Scheduled weekly.
 */
class PruneGeoEventsCommand extends Command
{
    protected $signature = 'sandhog:prune-geo-events';

    protected $description = 'Delete GEO check activity-log rows older than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) config('launchpad.geo.events.retention_days', 7));
        $cutoff = Carbon::now()->subDays($days);

        $deleted = GeoCheckEvent::withoutGlobalScope(SiteScope::class)
            ->where('created_at', '<', $cutoff)->delete();

        $this->line("<info>Pruned</info> {$deleted} GEO check event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
