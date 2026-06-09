<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\PipelineTrigger;
use App\Enums\SiteStatus;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled §5 driver — the missing caller. Per engine-eligible site it runs the
 * cadence-gated refresh (discovery + position tracking); the per-site cadence
 * lives in SitePipelineRefresher, so quiet sites cost nothing. Per-site failures
 * are isolated and logged — one tenant's failure can't abort the run, and the
 * artifact-based cadence naturally retries it next cycle.
 */
class RefreshKeywordPipelines implements ShouldQueue
{
    use Queueable;

    /** Sites the engine runs for — past onboarding, not suspended. */
    private const ELIGIBLE = [SiteStatus::Active, SiteStatus::Building, SiteStatus::Live];

    public function handle(SitePipelineRefresher $refresher): void
    {
        Site::query()
            ->whereIn('status', array_map(fn (SiteStatus $s) => $s->value, self::ELIGIBLE))
            ->each(function (Site $site) use ($refresher): void {
                try {
                    $refresher->refresh($site, PipelineTrigger::Scheduled);
                } catch (Throwable $e) {
                    Log::warning('§5 pipeline refresh failed', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
