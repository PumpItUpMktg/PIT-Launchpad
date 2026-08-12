<?php

namespace App\Jobs;

use App\JobCapture\Geography\GeographyResolver;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolve a captured job's geography off the web request (§4). The capture flow / Joby ingest dispatches
 * this the moment a job has true coordinates, so the tech never waits on a Census round-trip — the
 * county + place FIPS, ACS population/tier, and the stored privacy jitter land quietly on the worker.
 * Delegates to {@see GeographyResolver} (idempotent — registry upsert by FIPS, jitter computed once). A
 * missing job or one without a true point is a quiet no-op.
 */
class ResolveJobGeography implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $jobId) {}

    public function handle(GeographyResolver $resolver): void
    {
        $job = Job::withoutGlobalScope(SiteScope::class)->find($this->jobId);
        if ($job === null) {
            return;
        }

        $resolver->resolve($job);
    }
}
