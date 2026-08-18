<?php

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Jobs\PingJobIndexNow;
use App\Models\Job;
use App\Models\Site;

test('the job public URL is the {domain}/jobs/{slug}/ form', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement']);

    // {title}-{last 6 of the ULID}, slugged, under the /jobs/ base.
    $expected = 'https://spg.example/jobs/sump-pump-replacement-'.strtolower(substr($job->id, -6)).'/';

    expect($job->publicUrl($site->domain_url))->toBe($expected)
        ->and($job->publicUrl(''))->toBeNull();
});

test('it pings IndexNow with the job URL and stamps indexnow_submitted_at on success', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement', 'indexnow_submitted_at' => null]);

    $submitter = Mockery::mock(IndexNowSubmitter::class);
    $submitter->shouldReceive('submitUrl')
        ->once()
        ->with(Mockery::on(fn ($s): bool => $s->id === $site->id), $job->publicUrl($site->domain_url))
        ->andReturn(['ok' => true, 'submitted' => 1, 'status' => 200, 'reason' => null]);

    (new PingJobIndexNow($job->id))->handle($submitter);

    expect($job->fresh()->indexnow_submitted_at)->not->toBeNull();
});

test('it does NOT stamp indexnow_submitted_at when the ping fails', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $job = Job::factory()->create(['site_id' => $site->id, 'post_title' => 'Sump Pump Replacement', 'indexnow_submitted_at' => null]);

    $submitter = Mockery::mock(IndexNowSubmitter::class);
    $submitter->shouldReceive('submitUrl')->once()->andReturn(['ok' => false, 'submitted' => 0, 'status' => 403, 'reason' => 'key_not_served']);

    (new PingJobIndexNow($job->id))->handle($submitter);

    expect($job->fresh()->indexnow_submitted_at)->toBeNull();
});
