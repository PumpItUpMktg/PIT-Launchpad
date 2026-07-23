<?php

use App\ContentEngine\Review\AlertFlags;
use App\ContentEngine\Review\ReviewQueue;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\PageType;
use App\Enums\ReviewFlag;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Service;
use App\Models\Silo;
use App\Models\Site;

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

test('needs_enrichment is flagged for a service page whose §1 service has no enrichment, and filters', function () {
    $site = Site::factory()->create();

    // A thin service (no symptoms/scope/process/cost) → its spoke page is flagged.
    $thin = Service::factory()->create([
        'site_id' => $site->id, 'name' => 'Basement Waterproofing',
        'symptoms' => [], 'scope_items' => [], 'process_steps' => [], 'cost_factors' => [],
    ]);
    $thinPage = Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview,
        'page_type' => PageType::Service, 'primary_service_id' => $thin->id,
    ]);

    // An enriched service → not flagged.
    $rich = Service::factory()->create([
        'site_id' => $site->id, 'name' => 'Sump Pump Installation',
        'symptoms' => ['Water pooling'], 'scope_items' => ['New basin'],
    ]);
    $richPage = Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview,
        'page_type' => PageType::Service, 'primary_service_id' => $rich->id,
    ]);

    expect(flagValues($thinPage))->toContain(ReviewFlag::NeedsEnrichment->value)
        ->and(flagValues($richPage))->not->toContain(ReviewFlag::NeedsEnrichment->value);

    $ids = AlertFlags::filter(ReviewQueue::query(), ReviewFlag::NeedsEnrichment)->pluck('id');
    expect($ids)->toContain($thinPage->id)->not->toContain($richPage->id);

    // Informational only — never blocks approval.
    expect(ReviewFlag::NeedsEnrichment->blocksApproval())->toBeFalse();
});

test('needs_generation is flagged for an undrafted hub, a drafted hub with no spokes, but not one with spokes', function () {
    $site = Site::factory()->create();
    $siloA = Silo::factory()->create(['site_id' => $site->id]);
    $siloB = Silo::factory()->create(['site_id' => $site->id]);
    $siloC = Silo::factory()->create(['site_id' => $site->id]);
    $siloD = Silo::factory()->create(['site_id' => $site->id]);

    // 1) Undrafted hub (empty slot payload) → flagged.
    $undrafted = Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview,
        'page_type' => PageType::Hub, 'silo_id' => $siloA->id, 'slot_payload' => [],
    ]);

    // 2) Drafted hub with NO materialized spoke in its silo → flagged (empty services grid).
    $orphanHub = Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview,
        'page_type' => PageType::Hub, 'silo_id' => $siloB->id,
        'slot_payload' => ['hub_intro' => 'x'],
    ]);

    // 3) Drafted hub WITH a spoke page in its silo → not flagged.
    $fullHub = Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview,
        'page_type' => PageType::Hub, 'silo_id' => $siloC->id, 'slot_payload' => ['hub_intro' => 'x'],
    ]);
    Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published,
        'page_type' => PageType::Service, 'silo_id' => $siloC->id, 'slug' => 'sump-pump-repair',
    ]);

    // A non-hub page is never flagged for generation.
    $service = Content::factory()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview,
        'page_type' => PageType::Service, 'silo_id' => $siloD->id, 'slot_payload' => [],
    ]);

    expect(flagValues($undrafted))->toContain(ReviewFlag::NeedsGeneration->value)
        ->and(flagValues($orphanHub))->toContain(ReviewFlag::NeedsGeneration->value)
        ->and(flagValues($fullHub))->not->toContain(ReviewFlag::NeedsGeneration->value)
        ->and(flagValues($service))->not->toContain(ReviewFlag::NeedsGeneration->value);

    $ids = AlertFlags::filter(ReviewQueue::query(), ReviewFlag::NeedsGeneration)->pluck('id');
    expect($ids)->toContain($undrafted->id)->toContain($orphanHub->id)
        ->not->toContain($fullHub->id)->not->toContain($service->id);

    // Informational only — never blocks approval.
    expect(ReviewFlag::NeedsGeneration->blocksApproval())->toBeFalse();
});
