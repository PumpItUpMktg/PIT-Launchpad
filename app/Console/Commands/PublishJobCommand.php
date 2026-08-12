<?php

namespace App\Console\Commands;

use App\JobCapture\Publishing\JobPublisher;
use App\Jobs\PublishJob;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Publish an approved job to WordPress (§9) — the synchronous manual / re-push twin of the
 * {@see PublishJob} queued job. Idempotent by ULID.
 */
class PublishJobCommand extends Command
{
    protected $signature = 'launchpad:publish-job {job : Job id}';

    protected $description = 'Publish an approved Job Capture job to WordPress (upsert the pig_job post by ULID).';

    public function handle(JobPublisher $publisher): int
    {
        $arg = (string) $this->argument('job');
        $job = Job::withoutGlobalScope(SiteScope::class)->find($arg);
        if ($job === null) {
            $this->error("No job matches [{$arg}].");

            return self::FAILURE;
        }

        $publisher->publish($job);

        $this->info("Published {$job->id} → {$job->refresh()->status->value}"
            .($job->wp_post_id !== null ? " (wp_post_id {$job->wp_post_id})" : ''));

        return self::SUCCESS;
    }
}
