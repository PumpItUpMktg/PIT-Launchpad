<?php

namespace App\Jobs;

use App\Activity\MonthlySnapshots;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled on the first of each month: freeze the month just ended for every site (and any earlier month
 * still missing). One site failing never stops the rest.
 */
class CloseMonthlySnapshots implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function handle(MonthlySnapshots $snapshots): void
    {
        foreach (Site::withoutGlobalScopes()->get() as $site) {
            try {
                $snapshots->backfill($site);
            } catch (Throwable $e) {
                Log::warning('Monthly snapshot failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
