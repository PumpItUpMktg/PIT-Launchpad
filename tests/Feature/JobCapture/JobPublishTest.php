<?php

use App\Enums\ConnectionProvider;
use App\Enums\JobStatus;
use App\JobCapture\Publishing\JobPublisher;
use App\Models\Connection;
use App\Models\Job;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/** An approved, drafted job on a site with a WordPress connection. */
function publishableJob(): Job
{
    $site = Site::factory()->create();
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://wp.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);

    return Job::factory()->create([
        'site_id' => $site->id,
        'status' => JobStatus::Approved,
        'client_name_display' => 'Jane H.',
        'post_title' => 'Sump Pump Replacement',
        'meta_description' => 'A sump pump replacement.',
        'enhanced_description' => 'We replaced the failed sump pump and cleared the pit.',
        'lat_true' => 40.70, 'lng_true' => -74.00,
        'lat_jittered' => 40.71, 'lng_jittered' => -74.01,
        'photos' => [['r2_key' => 'sites/x/jobs/y/1.jpg', 'alt' => 'A new sump pump']],
    ]);
}

test('it publishes an approved job to WordPress — keyed on the ULID, jittered coords only', function () {
    $captured = null;
    Http::fake(['*/launchpad/v1/job' => function ($request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['wp_post_id' => 77, 'status' => 'publish']);
    }]);

    $job = publishableJob();
    app(JobPublisher::class)->publish($job);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::Published)
        ->and($job->wp_post_id)->toBe(77)
        ->and($captured['job_id'])->toBe($job->id)                        // ULID upsert key, never a title match
        ->and($captured['location']['lat'])->toEqual((float) $job->lat_jittered)
        ->and($captured['location']['lat'])->not->toEqual((float) $job->lat_true)   // never the true point
        ->and($captured['images'][0]['alt'])->toBe('A new sump pump');
});

test('a WordPress failure lands the job in publish_failed with the error surfaced', function () {
    Http::fake(['*/launchpad/v1/job' => Http::response('nope', 500)]);

    $job = publishableJob();
    app(JobPublisher::class)->publish($job);

    expect($job->refresh()->status)->toBe(JobStatus::PublishFailed)
        ->and($job->last_publish_error)->not->toBeNull();
});

test('an un-enhanced job is never published (no empty post reaches WordPress)', function () {
    $job = publishableJob();
    $job->forceFill(['enhanced_description' => null])->save();

    app(JobPublisher::class)->publish($job);

    expect($job->refresh()->status)->toBe(JobStatus::Approved);   // unchanged, no push
});

test('unpublish pulls the post down and clears wp_post_id', function () {
    Http::fake(['*/launchpad/v1/job/delete' => Http::response(['deleted' => true])]);

    $job = publishableJob();
    $job->forceFill(['status' => JobStatus::Published, 'wp_post_id' => 77])->save();

    app(JobPublisher::class)->unpublish($job);

    expect($job->refresh()->wp_post_id)->toBeNull();
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/launchpad/v1/job/delete'));
});
