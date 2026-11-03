<?php

namespace App\Jobs;

use App\ColdOutreach\ColdPipeline;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One pass of the cold outreach pipeline for one site (verify → enroll → sync → promote). Unique per
 * site so the hourly sweep and an operator's "Run now" never overlap on the same tenant.
 */
class AdvanceColdPipeline implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(ColdPipeline $pipeline): void
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find($this->siteId);
        if ($site !== null) {
            $pipeline->advance($site);
        }
    }
}
