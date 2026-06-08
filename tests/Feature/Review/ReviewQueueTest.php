<?php

use App\ContentEngine\Review\ReviewQueue;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;

test('the queue lists only the actionable statuses', function () {
    $needsReview = Content::factory()->create(['status' => ContentStatus::NeedsReview]);
    $inReview = Content::factory()->create(['status' => ContentStatus::InReview]);
    $renderFailed = Content::factory()->create(['status' => ContentStatus::RenderFailed]);
    Content::factory()->create(['status' => ContentStatus::Published]);
    Content::factory()->create(['status' => ContentStatus::Candidate]);

    $ids = ReviewQueue::query()->pluck('id');

    expect($ids)->toHaveCount(3)
        ->toContain($needsReview->id)
        ->toContain($inReview->id)
        ->toContain($renderFailed->id);
});

test('flagged items sort before plain drafts even when newer', function () {
    $oldDraft = Content::factory()->create(['status' => ContentStatus::NeedsReview, 'created_at' => now()->subDays(5)]);
    $newFailed = Content::factory()->create(['status' => ContentStatus::RenderFailed, 'created_at' => now()]);

    $ordered = ReviewQueue::flaggedFirst(ReviewQueue::query())->pluck('id')->all();

    // render_failed bubbles to the top despite being newer.
    expect($ordered[0])->toBe($newFailed->id)
        ->and($ordered[1])->toBe($oldDraft->id);
});

test('the queue is filterable by tenant, silo, kind and trigger', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $siteA->id]);

    $target = Content::factory()->create([
        'site_id' => $siteA->id,
        'silo_id' => $silo->id,
        'kind' => ContentKind::Post,
        'draft_trigger' => DraftTrigger::Gap,
        'status' => ContentStatus::NeedsReview,
    ]);
    Content::factory()->create(['site_id' => $siteB->id, 'status' => ContentStatus::NeedsReview]);

    expect(ReviewQueue::query()->where('site_id', $siteA->id)->pluck('id'))->toContain($target->id)->toHaveCount(1)
        ->and(ReviewQueue::query()->where('silo_id', $silo->id)->pluck('id'))->toContain($target->id)
        ->and(ReviewQueue::query()->where('kind', ContentKind::Post->value)->pluck('id'))->toContain($target->id)
        ->and(ReviewQueue::query()->where('draft_trigger', DraftTrigger::Gap->value)->pluck('id'))->toContain($target->id);
});
