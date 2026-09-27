<?php

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Jobs\RunCitationScan;
use App\Jobs\SyncSiteMetrics;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;
use App\Support\Tenancy\ResetTenantOnJobBoundary;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

function boundaryJob(): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn(SyncSiteMetrics::class);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn([]);   // the framework's own Context listener reads it too
    $job->shouldIgnoreMissing();

    return $job;
}

it('clears a leaked tenant lock before the next job starts, after a job ends, and when a job fails', function (): void {
    $listener = new ResetTenantOnJobBoundary;

    CurrentSite::set('site-a');
    $listener->processing(new JobProcessing('database', boundaryJob()));
    expect(CurrentSite::id())->toBeNull();

    CurrentSite::set('site-a');
    $listener->processed(new JobProcessed('database', boundaryJob()));
    expect(CurrentSite::id())->toBeNull();

    CurrentSite::set('site-a');
    $listener->failed(new JobFailed('database', boundaryJob(), new RuntimeException('x')));
    expect(CurrentSite::id())->toBeNull();
});

it('is wired to the worker events, so a job that forgets to clear its lock cannot poison the next job', function (): void {
    CurrentSite::set('site-a');

    event(new JobProcessing('database', boundaryJob()));

    expect(CurrentSite::id())->toBeNull();
});

it('a citation scan releases its tenant lock when it finishes — even when run inline', function (): void {
    $site = Site::factory()->create();
    $location = Location::factory()->for($site)->create();
    LocationNapProfile::factory()->for($site)->create(['location_id' => $location->id, 'business_name' => 'ACME', 'categories' => null]);
    app()->instance(DataForSeoClient::class, new class extends DataForSeoClient
    {
        public function __construct() {}

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return [];
        }
    });

    app()->call([new RunCitationScan($location->id, sweepSharedNumbers: false, trigger: 'manual'), 'handle']);

    expect(CurrentSite::id())->toBeNull();
});
