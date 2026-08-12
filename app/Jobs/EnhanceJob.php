<?php

namespace App\Jobs;

use App\JobCapture\Enhancement\JobEnhancer;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Enhance a captured job off the web request (§7). Fired after capture/sync (never blocking the tech) and
 * re-runnable by the operator. Bounded like the other model-calling jobs: an explicit timeout and
 * `$tries = 1` so an expensive Sonnet call never retry-storms — a failure (no write-up produced) lands in
 * `failed_jobs`, leaving the job re-enhanceable rather than advancing it with empty content.
 */
class EnhanceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public readonly string $jobId) {}

    public function handle(JobEnhancer $enhancer): void
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->find($this->jobId);
        if ($job === null) {
            return;
        }

        $enhancer->enhance($job);
    }
}
