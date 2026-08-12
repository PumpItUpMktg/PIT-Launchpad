<?php

namespace App\Jobs;

use App\JobCapture\Publishing\JobPublisher;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publish an approved Job Capture job to WordPress (§9) — assemble the ULID-keyed meta-blob and upsert the
 * `pig_job` post. Idempotent by ULID (a re-dispatch updates rather than duplicates), so it is safely
 * retryable; a bounded transient retry lands an exhausted push in `publish_failed`. Unique per job id
 * ({@see ShouldBeUnique}) so overlapping approve/re-publish clicks can't stack duplicate pushes.
 */
class PublishJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public readonly string $jobId) {}

    public function uniqueId(): string
    {
        return $this->jobId;
    }

    public function handle(JobPublisher $publisher): void
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->find($this->jobId);
        if ($job !== null) {
            $publisher->publish($job);
        }
    }
}
