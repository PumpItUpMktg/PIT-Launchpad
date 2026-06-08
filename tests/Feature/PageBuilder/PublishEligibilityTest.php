<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\PageBuilder\Validation\PublishEligibility;
use App\PageBuilder\Validation\ValidationCode;
use App\PageBuilder\Validation\ValidationContext;
use Tests\Support\PageBuilder;

function contentForServiceKit(array $backed, array $payload): Content
{
    $kit = PageBuilder::seedServiceKitModel();

    return Content::factory()->create([
        'site_id' => $backed['site']->id,
        'wireframe_kit_id' => $kit->id,
        'wireframe_kit_version' => $kit->version,
        'slot_payload' => $payload,
        'status' => ContentStatus::Drafted,
    ]);
}

test('a valid page passes publish-eligibility and keeps its status', function () {
    $backed = PageBuilder::backedSite();
    $content = contentForServiceKit($backed, PageBuilder::validServicePayload());

    $context = new ValidationContext($content, $backed['market'], ['is_storefront' => true]);

    $result = app(PublishEligibility::class)->evaluate($content, $context);

    expect($result->passed())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Drafted);
});

test('an invalid page is parked in review with structured reasons', function () {
    $backed = PageBuilder::backedSite();
    $payload = PageBuilder::validServicePayload();
    unset($payload['hero_problem']);

    $content = contentForServiceKit($backed, $payload);
    $context = new ValidationContext($content, $backed['market'], ['is_storefront' => true]);

    $result = app(PublishEligibility::class)->evaluate($content, $context);

    expect($result->failed())->toBeTrue()
        ->and($result->hasCode(ValidationCode::MissingRequiredSlot))->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::InReview);
});
