<?php

use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Site;
use App\PageBuilder\Validation\KitValidator;
use App\PageBuilder\Validation\PublishEligibility;
use Tests\Support\PageBuilder;

/**
 * §3a service-page entity-slot policy (Eric): cta + contact_block are DERIVABLE and
 * never block (cta derives the location phone; contact_block the NAP), and
 * proof_strip is CONDITIONAL — it omits below 2 substantiated proof rather than
 * blocking, like testimonial. A real tenant (≥1 location) therefore publishes a
 * service page with no conversion config and no proof.
 */
function servicePage(Site $site): Content
{
    return Content::factory()->page()->create([
        'site_id' => $site->id,
        'page_type' => PageType::Service,
    ]);
}

it('clears cta / contact_block / proof_strip for a bare tenant (location only, no proof, no conversion)', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id]); // has_location → contact_block applies
    $content = servicePage($site);

    $context = app(PublishEligibility::class)->contextFor($content);
    $result = app(KitValidator::class)->validate(PageBuilder::serviceKit(), PageBuilder::validServicePayload(), $context);

    expect($result->failuresFor('cta'))->toBe([])            // entity gate dropped — never blocks
        ->and($result->failuresFor('contact_block'))->toBe([]) // derives NAP from the location
        ->and($result->failuresFor('proof_strip'))->toBe([]);  // <2 proof → conditional omits
});

it('omits contact_block when the tenant has zero locations', function () {
    $site = Site::factory()->create(); // no location
    $context = app(PublishEligibility::class)->contextFor(servicePage($site));

    expect($context->flags['has_location'])->toBeFalse();

    $result = app(KitValidator::class)->validate(PageBuilder::serviceKit(), PageBuilder::validServicePayload(), $context);
    expect($result->failuresFor('contact_block'))->toBe([]); // skipped, not failed
});

it('applies proof_strip only when >= 2 substantiated proof exist', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id]);
    ProofItem::factory()->count(2)->create(['site_id' => $site->id, 'is_substantiated' => true]);

    $context = app(PublishEligibility::class)->contextFor(servicePage($site));

    expect($context->flags['has_substantiated_proof'])->toBeTrue()
        ->and($context->flags['has_location'])->toBeTrue();

    $result = app(KitValidator::class)->validate(PageBuilder::serviceKit(), PageBuilder::validServicePayload(), $context);
    expect($result->failuresFor('proof_strip'))->toBe([]); // applies, ≥2 proof satisfies the gate
});
