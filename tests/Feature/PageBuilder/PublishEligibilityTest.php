<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Market;
use App\Models\Site;
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

/**
 * The publish-time flags a fully entity-backed site resolves to (proof ≥ 2,
 * reviews ≥ 1, a location) — the conditions proof_strip / testimonial /
 * contact_block gate on. backedSite() wires all of them.
 *
 * @param  array{site: Site, market: Market, content: Content}  $backed
 */
function backedContext(array $backed, Content $content): ValidationContext
{
    return new ValidationContext($content, $backed['market'], [
        'is_storefront' => true,
        'has_reviews' => true,
        'has_substantiated_proof' => true,
        'has_location' => true,
    ]);
}

test('a valid page passes publish-eligibility and keeps its status', function () {
    $backed = PageBuilder::backedSite();
    $content = contentForServiceKit($backed, PageBuilder::validServicePayload());

    $result = app(PublishEligibility::class)->evaluate($content, backedContext($backed, $content));

    expect($result->passed())->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::Drafted);
});

test('an invalid page is parked in review with structured reasons', function () {
    $backed = PageBuilder::backedSite();
    $payload = PageBuilder::validServicePayload();
    unset($payload['hero_problem']);

    $content = contentForServiceKit($backed, $payload);

    $result = app(PublishEligibility::class)->evaluate($content, backedContext($backed, $content));

    expect($result->failed())->toBeTrue()
        ->and($result->hasCode(ValidationCode::MissingRequiredSlot))->toBeTrue()
        ->and($content->fresh()->status)->toBe(ContentStatus::InReview);
});
