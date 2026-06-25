<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Publishing\PagePreviewService;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

it('preview-push sends a WP DRAFT and never changes the content status', function () {
    PublishHarness::fakeAdapters();
    Http::fake([
        '*/launchpad/v1/content' => Http::response([
            'content_id' => 'x', 'wp_post_id' => 55, 'status' => 'draft', 'skipped' => false,
            'preview_url' => 'https://wp.apex.example/?p=55&preview=1',
        ], 200),
    ]);

    $site = PublishHarness::site();
    $page = PublishHarness::approvedPage($site);
    // a drafted page sitting in review (not yet approved) — the proof step previews here
    $page->forceFill(['status' => ContentStatus::NeedsReview])->save();

    $result = app(PagePreviewService::class)->preview($page->fresh());

    expect($result->isReady())->toBeTrue()
        ->and($result->wpPostId)->toBe(55)
        ->and($result->previewUrl)->toBe('https://wp.apex.example/?p=55&preview=1')
        // the preview is NOT a publish — status is untouched, nothing went live
        ->and($page->fresh()->status)->toBe(ContentStatus::NeedsReview);

    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/wp-json/launchpad/v1/content')
        && $req['status'] === 'draft'
        && $req['content_id'] === $page->id);
});

it('preview is unavailable until the page has a draft', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $page = Content::factory()->page()->create(['site_id' => $site->id, 'slot_payload' => []]);

    $result = app(PagePreviewService::class)->preview($page);

    expect($result->isReady())->toBeFalse()
        ->and($result->message)->toContain('no draft');
    Http::assertNothingSent();
});
