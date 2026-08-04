<?php

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Jobs\PingIndexNow;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PublishHarness;

function inFakeContentEndpoint(): void
{
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 123, 'status' => 'publish', 'skipped' => false], 200)]);
}

it('pings IndexNow after a successful publish (config on)', function () {
    config()->set('services.indexnow.ping_on_publish', true); // default off in the test env
    Queue::fake();
    PublishHarness::fakeAdapters();
    inFakeContentEndpoint();

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    app(PublishContentService::class)->publish($content, 'operator-1');

    Queue::assertPushed(PingIndexNow::class, fn (PingIndexNow $job) => $job->contentId === $content->id);
});

it('does not ping IndexNow when ping_on_publish is off', function () {
    config()->set('services.indexnow.ping_on_publish', false);
    Queue::fake();
    PublishHarness::fakeAdapters();
    inFakeContentEndpoint();

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    app(PublishContentService::class)->publish($content, 'operator-1');

    Queue::assertNotPushed(PingIndexNow::class);
});

it('stamps indexnow_submitted_at on the content when the ping succeeds', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $content = Content::factory()->create(['site_id' => $site->id, 'status' => 'published', 'slug' => 'x', 'wp_post_id' => 1, 'indexnow_submitted_at' => null]);

    $submitter = Mockery::mock(IndexNowSubmitter::class);
    $submitter->shouldReceive('submitUrl')->once()->andReturn(['ok' => true, 'submitted' => 1, 'status' => 200, 'reason' => null]);

    (new PingIndexNow($content->id))->handle($submitter);

    expect($content->fresh()->indexnow_submitted_at)->not->toBeNull();
});

it('does NOT stamp indexnow_submitted_at when the ping fails', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $content = Content::factory()->create(['site_id' => $site->id, 'status' => 'published', 'slug' => 'y', 'wp_post_id' => 2, 'indexnow_submitted_at' => null]);

    $submitter = Mockery::mock(IndexNowSubmitter::class);
    $submitter->shouldReceive('submitUrl')->once()->andReturn(['ok' => false, 'submitted' => 0, 'status' => 403, 'reason' => 'key_not_served']);

    (new PingIndexNow($content->id))->handle($submitter);

    expect($content->fresh()->indexnow_submitted_at)->toBeNull();
});
