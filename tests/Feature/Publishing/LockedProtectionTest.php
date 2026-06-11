<?php

use App\Enums\ContentStatus;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

test('a locked page is never overwritten — publish skips without pushing', function () {
    PublishHarness::fakeAdapters();
    Http::fake();

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->update(['locked' => true]);

    $result = app(PublishContentService::class)->publish($content);

    // A skip resolves the transitional status back to published (the live page is
    // kept) and surfaces the reason — it never strands the row.
    expect($result->wasSkipped())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Published)
        ->and($content->fresh()->last_publish_error)->toContain('locked or locally edited')
        ->and($result->message)->toContain('locked or locally edited');

    Http::assertNothingSent();
});

test('a locally_edited page is never overwritten', function () {
    PublishHarness::fakeAdapters();
    Http::fake();

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->update(['locally_edited' => true]);

    expect(app(PublishContentService::class)->publish($content)->wasSkipped())->toBeTrue();

    Http::assertNothingSent();
});

test('when WordPress reports the page locked, the push is honored as skipped and the row resolves to published', function () {
    PublishHarness::fakeAdapters();
    Http::fake([
        '*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 9, 'skipped' => true], 200),
    ]);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content);

    // The push was declined by design — the live page stays, so the transitional
    // 'publishing' status must resolve to published, not strand. (Was the bug.)
    expect($result->wasSkipped())->toBeTrue()
        ->and($content->fresh()->locally_edited)->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Published)
        ->and($content->fresh()->last_publish_error)->toContain('not overwritten');
});
