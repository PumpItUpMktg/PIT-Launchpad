<?php

namespace App\Console\Commands;

use App\JobCapture\Geography\GeographyResolver;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Resolve captured jobs to canonical city/county FIPS and stamp the privacy jitter (§4) — the manual /
 * backfill twin of the {@see ResolveJobGeography} queued job. Pass a job id to resolve one, or
 * `--all` to sweep every job that still has no resolved city but does carry true coordinates. Idempotent.
 */
class ResolveJobGeographyCommand extends Command
{
    protected $signature = 'launchpad:resolve-job-geography {job? : Job id (resolve one)} {--all : resolve every job missing geography}';

    protected $description = 'Resolve captured jobs to city/county FIPS + store privacy jitter (§4 geography).';

    public function handle(GeographyResolver $resolver): int
    {
        $arg = trim((string) $this->argument('job'));

        if ($arg !== '') {
            $job = Job::withoutGlobalScope(SiteScope::class)->find($arg);
            if ($job === null) {
                $this->error("No job matches [{$arg}].");

                return self::FAILURE;
            }
            $jobs = collect([$job]);
        } elseif ($this->option('all')) {
            $jobs = Job::withoutGlobalScope(SiteScope::class)
                ->whereNull('job_city_id')
                ->whereNotNull('lat_true')
                ->get();
        } else {
            $this->error('Pass a job id or --all.');

            return self::FAILURE;
        }

        foreach ($jobs as $job) {
            $resolver->resolve($job);
            $this->line("Resolved {$job->id} → city={$job->job_city_id} county={$job->job_county_id}");
        }

        $this->info($jobs->count().' job(s) resolved.');

        return self::SUCCESS;
    }
}
