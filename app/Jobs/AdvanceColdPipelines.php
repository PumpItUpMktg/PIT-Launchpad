<?php

namespace App\Jobs;

use App\Models\ColdOutreachConfig;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The scheduled sweep: queue a pipeline pass for every site that has cold outreach switched on. */
class AdvanceColdPipelines implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ColdOutreachConfig::withoutGlobalScope(SiteScope::class)
            ->where('enabled', true)
            ->pluck('site_id')
            ->each(fn ($siteId) => AdvanceColdPipeline::dispatch((string) $siteId));
    }
}
