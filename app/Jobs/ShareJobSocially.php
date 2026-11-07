<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Enums\SocialTrigger;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Social\SocialSharer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queues a published job's shares (one per enabled channel of its site). Dispatched by the job publisher. */
class ShareJobSocially implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $jobId, public readonly string $trigger = SocialTrigger::JobPublished->value) {}

    public function handle(SocialSharer $sharer): void
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->with(['site', 'city', 'jobTypes'])->find($this->jobId);
        if ($job === null || $job->status !== JobStatus::Published) {
            return;
        }
        $sharer->shareJob($job, SocialTrigger::from($this->trigger));
    }
}
