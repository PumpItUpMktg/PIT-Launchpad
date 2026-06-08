<?php

use App\ContentEngine\Review\AlertFlags;
use App\ContentEngine\Review\ReviewQueue;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\ReviewFlag;
use App\Models\Content;
use App\Models\RenderJob;

function flagValues(Content $content): array
{
    return array_map(fn (ReviewFlag $f) => $f->value, AlertFlags::for($content));
}

test('render_failed is flagged from a failed required render job and filters', function () {
    $content = Content::factory()->create(['status' => ContentStatus::NeedsReview]);
    RenderJob::factory()->failed()->create(['site_id' => $content->site_id, 'content_id' => $content->id, 'required' => true]);

    expect(flagValues($content))->toContain(ReviewFlag::RenderFailed->value);
    expect(AlertFlags::filter(ReviewQueue::query(), ReviewFlag::RenderFailed)->pluck('id'))->toContain($content->id);
});

test('unsupported_claim is flagged from verification and filters', function () {
    $flagged = Content::factory()->create([
        'status' => ContentStatus::NeedsReview,
        'verification' => ['unsupported_claims' => [['text' => 'x', 'claim_id' => null]]],
    ]);
    $clean = Content::factory()->create([
        'status' => ContentStatus::NeedsReview,
        'verification' => ['unsupported_claims' => []],
    ]);

    expect(flagValues($flagged))->toContain(ReviewFlag::UnsupportedClaim->value)
        ->and(flagValues($clean))->not->toContain(ReviewFlag::UnsupportedClaim->value);

    $ids = AlertFlags::filter(ReviewQueue::query(), ReviewFlag::UnsupportedClaim)->pluck('id');
    expect($ids)->toContain($flagged->id)->not->toContain($clean->id);
});

test('near-duplicate is flagged from the linkage and filters', function () {
    $original = Content::factory()->create(['status' => ContentStatus::Published]);
    $dup = Content::factory()->create([
        'status' => ContentStatus::NeedsReview,
        'near_dup_of_content_id' => $original->id,
    ]);

    expect(flagValues($dup))->toContain(ReviewFlag::NearDuplicate->value);
    expect(AlertFlags::filter(ReviewQueue::query(), ReviewFlag::NearDuplicate)->pluck('id'))->toContain($dup->id);
});

test('on-demand is flagged from a non-reactive trigger and filters', function () {
    $gap = Content::factory()->create(['status' => ContentStatus::NeedsReview, 'draft_trigger' => DraftTrigger::Gap]);
    $news = Content::factory()->create(['status' => ContentStatus::NeedsReview, 'draft_trigger' => DraftTrigger::News]);

    expect(flagValues($gap))->toContain(ReviewFlag::OnDemand->value)
        ->and(flagValues($news))->not->toContain(ReviewFlag::OnDemand->value);

    $ids = AlertFlags::filter(ReviewQueue::query(), ReviewFlag::OnDemand)->pluck('id');
    expect($ids)->toContain($gap->id)->not->toContain($news->id);
});

test('borderline relevance is flagged from the in_review status and filters', function () {
    $borderline = Content::factory()->create(['status' => ContentStatus::InReview]);

    expect(flagValues($borderline))->toContain(ReviewFlag::RelevanceBand->value);
    expect(AlertFlags::filter(ReviewQueue::query(), ReviewFlag::RelevanceBand)->pluck('id'))->toContain($borderline->id);
});

test('brand-safety is flagged from the meta flag and filters', function () {
    $unsafe = Content::factory()->create([
        'status' => ContentStatus::NeedsReview,
        'meta' => ['flags' => ['brand_safety' => true]],
    ]);

    expect(flagValues($unsafe))->toContain(ReviewFlag::BrandSafety->value);
    expect(AlertFlags::filter(ReviewQueue::query(), ReviewFlag::BrandSafety)->pluck('id'))->toContain($unsafe->id);
});
