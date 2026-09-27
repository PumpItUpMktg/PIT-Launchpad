<?php

namespace App\Support\Tenancy;

use App\Support\CurrentSite;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * A queue worker keeps one container across every job it runs, so a tenant lock a job sets with
 * {@see CurrentSite::set()} and never clears is still locked when the NEXT job starts — and a job for another
 * site then trips the cross-tenant write guard ("Refusing to write MetricSyncRun for site B while tenant A is
 * locked"). This listener clears the lock at every job boundary — before a job starts, after it finishes,
 * and when it fails — so no job ever inherits another's tenant. Jobs still set their own lock as they do
 * today; they just can't leak it any more.
 */
final class ResetTenantOnJobBoundary
{
    public function processing(JobProcessing $event): void
    {
        CurrentSite::clear();
    }

    public function processed(JobProcessed $event): void
    {
        CurrentSite::clear();
    }

    public function failed(JobFailed $event): void
    {
        CurrentSite::clear();
    }
}
