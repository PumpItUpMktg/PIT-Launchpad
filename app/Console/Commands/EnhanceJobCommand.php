<?php

namespace App\Console\Commands;

use App\JobCapture\Enhancement\JobEnhancer;
use App\Jobs\EnhanceJob;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enhance a captured job into its SEO write-up (§7) — the synchronous manual / re-enhance twin of the
 * {@see EnhanceJob} queued job. Runs the model call inline (no FPM clock on the console) and
 * surfaces a failure rather than silently advancing the job.
 */
class EnhanceJobCommand extends Command
{
    protected $signature = 'launchpad:enhance-job {job : Job id}';

    protected $description = 'Enhance a captured job into an SEO write-up + title + meta + photo alt text (§7).';

    public function handle(JobEnhancer $enhancer): int
    {
        $arg = (string) $this->argument('job');
        $job = Job::withoutGlobalScope(SiteScope::class)->find($arg);
        if ($job === null) {
            $this->error("No job matches [{$arg}].");

            return self::FAILURE;
        }

        try {
            $enhancer->enhance($job);
        } catch (Throwable $e) {
            $this->error('Enhancement failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Enhanced {$job->id} → {$job->refresh()->status->value}.");

        return self::SUCCESS;
    }
}
