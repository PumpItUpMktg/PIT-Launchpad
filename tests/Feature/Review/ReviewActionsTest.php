<?php

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\RenderJob;
use Illuminate\Support\Facades\Bus;

function reviewActions(): ReviewActions
{
    return app(ReviewActions::class);
}

test('approve accepts the draft into approved WITHOUT publishing (publish is its own step)', function () {
    Bus::fake();
    $content = Content::factory()->create(['status' => ContentStatus::NeedsReview]);

    $result = reviewActions()->approve($content, 'operator-1');

    expect($result->approved)->toBeTrue()
        ->and($result->isBlocked())->toBeFalse()
        ->and($content->fresh()->status)->toBe(ContentStatus::Approved);

    // Approve is a Launchpad-only acceptance — no WordPress contact until Publish.
    Bus::assertNotDispatched(PublishContent::class);
});

test('publish enqueues the compose-and-push for an approved page', function () {
    Bus::fake();
    $content = Content::factory()->create(['status' => ContentStatus::Approved]);

    $result = reviewActions()->publish($content, 'operator-1');

    expect($result->approved)->toBeTrue()
        ->and($result->isBlocked())->toBeFalse();

    Bus::assertDispatched(PublishContent::class, fn (PublishContent $job) => $job->contentId === $content->id
        && $job->actorId === 'operator-1');
});

test('publish honors the render_failed guard — a partial page never pushes', function () {
    Bus::fake();
    $content = Content::factory()->create(['status' => ContentStatus::Approved]);
    RenderJob::factory()->failed()->create(['site_id' => $content->site_id, 'content_id' => $content->id, 'required' => true]);

    $result = reviewActions()->publish($content);

    expect($result->isBlocked())->toBeTrue();
    Bus::assertNotDispatched(PublishContent::class);
});

test('bulkPublish dispatches one compose-and-push job per approved page', function () {
    Bus::fake();
    $a = Content::factory()->create(['status' => ContentStatus::Approved]);
    $b = Content::factory()->create(['status' => ContentStatus::Approved]);

    $results = reviewActions()->bulkPublish([$a, $b]);

    expect($results[$a->id]->approved)->toBeTrue()
        ->and($results[$b->id]->approved)->toBeTrue();
    Bus::assertDispatchedTimes(PublishContent::class, 2);
});

test('approve is blocked for an undrafted candidate and dispatches nothing', function () {
    Bus::fake();
    // A borderline candidate sits in_review undrafted (no body) — must not approve.
    $content = Content::factory()->post()->create(['status' => ContentStatus::InReview, 'body' => null]);

    $result = reviewActions()->approve($content);

    expect($result->isBlocked())->toBeTrue()
        ->and($result->blockedReason)->toContain('no completed draft')
        ->and($content->fresh()->status)->toBe(ContentStatus::InReview);

    Bus::assertNotDispatched(PublishContent::class);
});

test('a required-image render_failed blocks approve and dispatches nothing', function () {
    Bus::fake();
    $content = Content::factory()->create(['status' => ContentStatus::NeedsReview]);
    RenderJob::factory()->failed()->create([
        'site_id' => $content->site_id,
        'content_id' => $content->id,
        'required' => true,
    ]);

    $result = reviewActions()->approve($content);

    expect($result->isBlocked())->toBeTrue()
        ->and($result->blockedReason)->toContain('render')
        ->and($content->fresh()->status)->toBe(ContentStatus::NeedsReview);

    Bus::assertNotDispatched(PublishContent::class);
});

test('an unsupported claim warns but still approves (the operator decides)', function () {
    Bus::fake();
    $content = Content::factory()->create([
        'status' => ContentStatus::NeedsReview,
        'verification' => ['unsupported_claims' => [['text' => 'We are #1', 'claim_id' => null]]],
    ]);

    $result = reviewActions()->approve($content);

    expect($result->approved)->toBeTrue()
        ->and($result->warnings)->not->toBeEmpty();

    // The warning rides on approve, but publishing is still a separate, deliberate step.
    Bus::assertNotDispatched(PublishContent::class);
});

test('reject sets rejected with a reason', function () {
    $content = Content::factory()->create(['status' => ContentStatus::NeedsReview]);

    reviewActions()->reject($content, 'Off-brand angle');

    $fresh = $content->fresh();
    expect($fresh->status)->toBe(ContentStatus::Rejected)
        ->and($fresh->reject_reason)->toBe('Off-brand angle');
});

test('lock sets the locked flag', function () {
    $content = Content::factory()->create(['status' => ContentStatus::NeedsReview, 'locked' => false]);

    reviewActions()->lock($content);

    expect($content->fresh()->locked)->toBeTrue();
});

test('bulk-approve applies the same render_failed guard per item', function () {
    Bus::fake();
    $ok = Content::factory()->create(['status' => ContentStatus::NeedsReview]);
    $blocked = Content::factory()->create(['status' => ContentStatus::NeedsReview]);
    RenderJob::factory()->failed()->create(['site_id' => $blocked->site_id, 'content_id' => $blocked->id, 'required' => true]);

    $results = reviewActions()->bulkApprove([$ok, $blocked]);

    expect($results[$ok->id]->approved)->toBeTrue()
        ->and($results[$blocked->id]->isBlocked())->toBeTrue()
        ->and($ok->fresh()->status)->toBe(ContentStatus::Approved)
        ->and($blocked->fresh()->status)->toBe(ContentStatus::NeedsReview);

    // Bulk-approve accepts; it never publishes (that's bulkPublish).
    Bus::assertNotDispatched(PublishContent::class);
});

test('edit-in-place persists slot, body and SEO without clobbering image specs', function () {
    $content = Content::factory()->create([
        'status' => ContentStatus::NeedsReview,
        'slot_payload' => ['hero_problem' => 'old'],
        'meta' => ['seo' => ['title' => 'Old'], 'image_specs' => [['slot' => 'hero_image']]],
    ]);

    reviewActions()->saveEdits($content, [
        'slot_payload' => ['hero_problem' => 'new problem'],
        'body' => null,
        'seo' => ['title' => 'New Title', 'meta_description' => 'New meta'],
    ]);

    $fresh = $content->fresh();
    expect($fresh->slot_payload['hero_problem'])->toBe('new problem')
        ->and($fresh->meta['seo']['title'])->toBe('New Title')
        ->and($fresh->meta['seo']['meta_description'])->toBe('New meta')
        // The image specs the drafter emitted survive the SEO edit.
        ->and($fresh->meta['image_specs'])->toHaveCount(1);
});
