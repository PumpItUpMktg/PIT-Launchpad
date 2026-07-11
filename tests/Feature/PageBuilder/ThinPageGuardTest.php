<?php

use App\Models\Content;
use App\Models\Market;
use App\Models\ProofItem;
use App\Models\Site;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Validation\ThinPageGuard;
use App\PageBuilder\Validation\ValidationContext;
use Tests\Support\PageBuilder;

/**
 * A synthetic kit staking ONE entity-proof slot — the guard's contract fixture. The two locked
 * block-era kits no longer stake entity proof (their proof sections gate in the composer), so the
 * guard's semantics are pinned here independent of kit evolution.
 */
function proofStakingKit(): KitSchema
{
    return KitSchema::fromArray([
        'name' => 'proof-staking-fixture',
        'version' => 1,
        'page_type' => 'service',
        'elementor_template_ref' => 'fixture',
        'seo_profile_ref' => 'service',
        'slots' => [[
            'key' => 'testimonial',
            'label' => 'Testimonial',
            'content_type' => 'testimonial',
            'role' => 'proof',
            'source' => 'entity',
            'cardinality' => ['type' => 'repeater', 'min' => 1, 'max' => 3],
            'constraints' => ['required' => true, 'entity' => 'reviews.site'],
            'client_override' => false,
            'elementor_binding' => 'lp/repeater',
        ]],
    ]);
}

test('the thin-page guard fires when a kit stakes proof slots that resolve to zero entities', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id]);

    $context = new ValidationContext($content, $market, ['is_storefront' => true]);

    $result = app(ThinPageGuard::class)->evaluate(proofStakingKit(), $context);

    expect($result->earned)->toBeFalse()
        ->and($result->proofEntityCount)->toBe(0);
});

test('the thin-page guard passes when the staked proof slots are entity-backed', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    ProofItem::factory()->create([
        'site_id' => $site->id,
        'type' => \App\Enums\ProofType::Testimonial,
        'is_substantiated' => true,
    ]);
    $content = Content::factory()->create(['site_id' => $site->id, 'market_id' => $market->id]);

    $result = app(ThinPageGuard::class)->evaluate(
        proofStakingKit(),
        new ValidationContext($content, $market, ['is_storefront' => true]),
    );

    expect($result->earned)->toBeTrue()
        ->and($result->proofEntityCount)->toBeGreaterThan(0);
});

test('a kit with no entity-proof slots has nothing to earn here — the block-era kits pass trivially', function () {
    // Service, hub, and location pages gate reviews/jobs as provider-fed page SECTIONS (empty ⇒
    // omitted by the composer), so their kits stake no proof slots and the guard never holds them.
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id]);

    $context = new ValidationContext($content, $market, ['is_storefront' => true]);

    foreach ([PageBuilder::serviceKit(), PageBuilder::locationKit()] as $kit) {
        $result = app(ThinPageGuard::class)->evaluate($kit, $context);

        expect($result->earned)->toBeTrue()
            ->and($result->proofEntityCount)->toBe(0);
    }
});
