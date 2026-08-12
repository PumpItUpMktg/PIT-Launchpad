<?php

namespace App\Jobs;

use App\JobCapture\Publishing\JobPublisher;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pull a published Job Capture post down from WordPress (§9) — the unapprove path. Dispatched when a
 * previously-published job is rejected, so it never orphans a live post. Idempotent (a no-op if the post
 * is already gone).
 */
class UnpublishJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $jobId) {}

    public function handle(JobPublisher $publisher): void
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->find($this->jobId);
        if ($job !== null) {
            $publisher->unpublish($job);
        }
    }
}
