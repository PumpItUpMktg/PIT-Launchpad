<?php

namespace App\Jobs;

use App\Models\Site;
use App\Roadmap\CompetitorProfiler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refresh a site's own and competitor Google Business Profiles on the worker (§ Roadmap) — the "Refresh
 * profiles" button. Unique per site while queued.
 */
class RefreshGbpProfiles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(CompetitorProfiler $profiler): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site !== null) {
            $profiler->refresh($site);
        }
    }
}
