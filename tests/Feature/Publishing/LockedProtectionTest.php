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

    expect($result->wasSkipped())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Approved);

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

test('when WordPress reports the page locked, the push is honored as skipped (not published)', function () {
    PublishHarness::fakeAdapters();
    Http::fake([
        '*/wp-json/launchpad/v1/content' => Http::response(['wp_post_id' => 9, 'skipped' => true], 200),
    ]);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->wasSkipped())->toBeTrue()
        ->and($content->fresh()->locally_edited)->toBeTrue()
        ->and($content->fresh()->status)->not->toBe(ContentStatus::Published);
});
