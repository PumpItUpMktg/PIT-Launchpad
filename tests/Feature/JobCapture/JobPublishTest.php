<?php

use App\Enums\ConnectionProvider;
use App\Enums\JobStatus;
use App\Enums\MunicipalityType;
use App\JobCapture\Publishing\JobPublisher;
use App\Jobs\PingJobIndexNow;
use App\Models\Connection;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
        ->and($captured['images'][0]['alt'])->toBe('A new sump pump')
        ->and($captured['performed_at'])->toBeNull();                        // a capture-time job carries no work date
});

test('the job blob carries the town\'s official local sources — by the town\'s Census id, else by name — so the job page cites what the town page cites', function () {
    $captured = null;
    Http::fake(['*/launchpad/v1/job' => function ($request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['wp_post_id' => 78, 'status' => 'publish']);
    }]);
    $job = publishableJob();
    $city = JobCity::factory()->create(['name' => 'Bedminster', 'state' => 'NJ', 'place_geoid' => '3403504330']);
    $job->forceFill(['job_city_id' => $city->id])->save();
    $sources = [
        ['key' => 'municipality', 'label' => 'Bedminster, NJ — official municipal website', 'url' => 'https://www.bedminster.us/'],
        ['key' => 'flood_map', 'label' => 'FEMA flood map search for Bedminster, NJ', 'url' => 'https://msc.fema.gov/portal/search?AddressQuery=Bedminster%2C%20NJ'],
    ];
    CoverageArea::factory()->create(['site_id' => $job->site_id, 'name' => 'Bedminster', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3403504330', 'source_location_ids' => [], 'local_sources' => $sources, 'local_sources_resolved_at' => now()]);

    app(JobPublisher::class)->publish($job);
    expect($captured['sources'])->toBe([['label' => $sources[0]['label'], 'url' => $sources[0]['url']], ['label' => $sources[1]['label'], 'url' => $sources[1]['url']]]);

    // A town whose Census id differs but whose name and state match still finds its row; no row → no sources.
    $job2 = publishableJob();
    $city2 = JobCity::factory()->create(['name' => 'Far Hills', 'state' => 'NJ', 'place_geoid' => '3403522770']);
    $job2->forceFill(['job_city_id' => $city2->id])->save();
    CoverageArea::factory()->create(['site_id' => $job2->site_id, 'name' => 'Far Hills', 'state' => 'NJ', 'type' => MunicipalityType::Place, 'geo_id' => '3403522771', 'source_location_ids' => [], 'local_sources' => [$sources[1]], 'local_sources_resolved_at' => now()]);
    app(JobPublisher::class)->publish($job2);
    expect($captured['sources'])->toHaveCount(1);
    $job3 = publishableJob();
    app(JobPublisher::class)->publish($job3);
    expect($captured['sources'])->toBe([]);
});

test('a backfilled past job pushes the day the work was done, so the plugin can date the post from it', function () {
    $captured = null;
    Http::fake(['*/launchpad/v1/job' => function ($request) use (&$captured) {
        $captured = $request->data();

        return Http::response(['wp_post_id' => 78, 'status' => 'publish']);
    }]);

    $job = publishableJob();
    $job->forceFill(['performed_at' => '2026-04-14'])->save();
    app(JobPublisher::class)->publish($job->fresh());

    expect($captured['performed_at'])->toBe('2026-04-14');
});

test('a successful publish pings IndexNow for the job (when ping_on_publish is on)', function () {
    config()->set('services.indexnow.ping_on_publish', true);
    Queue::fake();
    Http::fake(['*/launchpad/v1/job' => Http::response(['wp_post_id' => 77, 'status' => 'publish'])]);

    $job = publishableJob();
    app(JobPublisher::class)->publish($job);

    Queue::assertPushed(PingJobIndexNow::class, fn (PingJobIndexNow $j): bool => $j->jobId === $job->id);
});

test('a successful publish does NOT ping IndexNow when ping_on_publish is off', function () {
    config()->set('services.indexnow.ping_on_publish', false);
    Queue::fake();
    Http::fake(['*/launchpad/v1/job' => Http::response(['wp_post_id' => 77, 'status' => 'publish'])]);

    app(JobPublisher::class)->publish(publishableJob());

    Queue::assertNotPushed(PingJobIndexNow::class);
});

test('a failed publish never pings IndexNow', function () {
    config()->set('services.indexnow.ping_on_publish', true);
    Queue::fake();
    Http::fake(['*/launchpad/v1/job' => Http::response('nope', 500)]);

    app(JobPublisher::class)->publish(publishableJob());

    Queue::assertNotPushed(PingJobIndexNow::class);
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
