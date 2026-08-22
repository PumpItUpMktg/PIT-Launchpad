<?php

namespace App\Jobs;

use App\Metrics\Milestones\MilestoneDeriver;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Derive a site's client milestones (§ Client Dashboard v1) as its own queued step — the tail of the
 * {@see RefreshSiteDashboard} chain, so it runs after the metric syncs have written the spine it reads.
 * Cheap and DB-only; short timeout.
 */
class DeriveSiteMilestones implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly string $siteId) {}

    public function handle(MilestoneDeriver $deriver): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        try {
            $deriver->derive($site);
        } catch (Throwable $e) {
            Log::warning('milestone derivation failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
