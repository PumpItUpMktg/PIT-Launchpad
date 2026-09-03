<?php

use App\Enums\JobStatus;
use App\Guided\LiveMetrics;
use App\JobCapture\Metrics\JobMetrics;
use App\Jobs\WarmLiveMetrics;
use App\Models\Job;
use App\Models\Site;

it('warms published JOBS too — their /jobs/{slug} paths the content warm never covers', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $live = Job::factory()->published()->create(['site_id' => $site->id]);
    Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Approved]); // not live → must NOT warm

    // No published Content exists → LiveMetrics is never invoked; JobMetrics is warmed once, for the live job.
    $liveMetrics = Mockery::mock(LiveMetrics::class);
    $jobMetrics = Mockery::mock(JobMetrics::class);
    $jobMetrics->shouldReceive('for')->once()
        ->with(Mockery::on(fn (Job $j): bool => $j->id === $live->id));

    (new WarmLiveMetrics($site->id))->handle($liveMetrics, $jobMetrics);
});
