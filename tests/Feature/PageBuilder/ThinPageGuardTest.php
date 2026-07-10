<?php

use App\Models\Content;
use App\Models\Market;
use App\Models\Site;
use App\PageBuilder\Validation\ThinPageGuard;
use App\PageBuilder\Validation\ValidationContext;
use Tests\Support\PageBuilder;

test('the thin-page guard fires when a kit stakes proof slots that resolve to zero entities', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id]);

    // The service kit's testimonial/proof_strip slots APPLY (flags on) but nothing backs them.
    $context = new ValidationContext($content, $market, [
        'is_storefront' => true, 'has_reviews' => true, 'has_proof' => true, 'has_substantiated_proof' => true,
    ]);

    $result = app(ThinPageGuard::class)->evaluate(PageBuilder::serviceKit(), $context);

    expect($result->earned)->toBeFalse()
        ->and($result->proofEntityCount)->toBe(0);
});

test('the thin-page guard passes when the staked proof slots are entity-backed', function () {
    $backed = PageBuilder::backedSite();

    $result = app(ThinPageGuard::class)->evaluate(
        PageBuilder::serviceKit(),
        PageBuilder::context($backed),
    );

    expect($result->earned)->toBeTrue()
        ->and($result->proofEntityCount)->toBeGreaterThan(0);
});

test('a kit with no entity-proof slots has nothing to earn here — the block-era location kit passes trivially', function () {
    // Location pages gate reviews/jobs as provider-fed page SECTIONS (empty ⇒ omitted by the
    // composer), so their kit stakes no entity slots and the guard never holds them.
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id]);

    $context = new ValidationContext($content, $market, ['is_storefront' => true]);

    $result = app(ThinPageGuard::class)->evaluate(PageBuilder::locationKit(), $context);

    expect($result->earned)->toBeTrue()
        ->and($result->proofEntityCount)->toBe(0);
});
