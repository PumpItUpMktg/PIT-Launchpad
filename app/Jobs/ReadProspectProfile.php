<?php

namespace App\Jobs;

use App\Links\Profile\ProspectProfiler;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Read one prospect's organisation profile on the worker (§ Links) — the "Read profile" click. */
class ReadProspectProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $prospectId) {}

    public function uniqueId(): string
    {
        return $this->prospectId;
    }

    public function handle(ProspectProfiler $profiler): void
    {
        $prospect = LinkProspect::withoutGlobalScope(SiteScope::class)->find($this->prospectId);
        if ($prospect !== null) {
            $profiler->read($prospect);
        }
    }
}
