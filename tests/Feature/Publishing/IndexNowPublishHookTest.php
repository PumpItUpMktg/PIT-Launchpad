<?php

use App\Jobs\PingIndexNow;
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
