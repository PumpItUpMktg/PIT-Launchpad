<?php

use App\Enums\AuditAction;
use App\Enums\ContentStatus;
use App\Models\AuditLog;
use App\Publishing\PublishContentService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

function fakeContentEndpoint(int $wpPostId = 123, bool $skipped = false): void
{
    Http::fake([
        '*/wp-json/launchpad/v1/content' => Http::response([
            'wp_post_id' => $wpPostId,
            'status' => 'publish',
            'skipped' => $skipped,
        ], 200),
    ]);
}

test('publishing renders, pushes the meta-blob by ULID, stores wp_post_id and audits', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 123);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content, 'operator-1');

    expect($result->isPublished())->toBeTrue()
        ->and($result->wpPostId)->toBe(123);

    $fresh = $content->fresh();
    expect($fresh->status)->toBe(ContentStatus::Published)
        ->and($fresh->wp_post_id)->toBe(123)
        ->and($fresh->published_at)->not->toBeNull()
        ->and($fresh->last_publish_error)->toBeNull();

    expect(AuditLog::where('action', AuditAction::ContentPublished->value)
        ->where('target_id', $content->id)->exists())->toBeTrue();

    Http::assertSent(function ($request) use ($content) {
        return str_contains($request->url(), '/wp-json/launchpad/v1/content')
            && $request['content_id'] === $content->id
            && $request['status'] === 'published'
            && $request['slot_payload']['hero_problem'] !== ''
            && is_string($request['images']['hero_image']['url'])
            && $request['seo']['title'] === 'Water Heater Repair in Austin'; // SEO title normalized (no "| Apex")
    });
});

test('a re-publish re-sends the same ULID (idempotent) and keeps wp_post_id', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 55);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    app(PublishContentService::class)->publish($content);
    app(PublishContentService::class)->publish($content->fresh());

    expect($content->fresh()->wp_post_id)->toBe(55);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['content_id'] === $content->id);
});

test('a needs_review row is NEVER published — the desync guard no-ops without mutating status', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint();

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->forceFill(['status' => ContentStatus::NeedsReview])->save();

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeFalse()
        ->and($content->fresh()->status)->toBe(ContentStatus::NeedsReview) // untouched — review flow intact
        ->and($content->fresh()->wp_post_id)->toBeNull();

    // The hole page 196 fell through: nothing reaches WordPress.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/launchpad/v1/content'));
});

test('candidate / scored / drafted / in_review / rejected rows are all guarded out', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site); // one row, re-stamped each pass

    foreach ([ContentStatus::Candidate, ContentStatus::Scored, ContentStatus::Drafted, ContentStatus::InReview, ContentStatus::Rejected] as $status) {
        $content->forceFill(['status' => $status])->save();

        app(PublishContentService::class)->publish($content->fresh());

        expect($content->fresh()->status)->toBe($status); // unchanged by the guard
    }

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/launchpad/v1/content'));
});

test('a render_failed row is allowed to re-publish (retry)', function () {
    PublishHarness::fakeAdapters();
    fakeContentEndpoint(wpPostId: 77);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $content->forceFill(['status' => ContentStatus::RenderFailed])->save();

    $result = app(PublishContentService::class)->publish($content);

    expect($result->isPublished())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Published);
});

test('a push failure lands the content in publish_failed with the error surfaced', function () {
    PublishHarness::fakeAdapters();
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response('', 500)]);

    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $result = app(PublishContentService::class)->publish($content);

    expect($result->hasFailed())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::PublishFailed)
        ->and($content->fresh()->last_publish_error)->not->toBeNull();
});
