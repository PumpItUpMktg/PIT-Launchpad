<?php

use App\Models\Content;
use App\Models\Market;
use App\Models\Site;
use App\PageBuilder\Validation\ThinPageGuard;
use App\PageBuilder\Validation\ValidationContext;
use Tests\Support\PageBuilder;

test('the thin-page guard fires when a market has no reviews and no recent work', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id]);

    $context = new ValidationContext($content, $market, ['is_storefront' => true]);

    $result = app(ThinPageGuard::class)->evaluate(PageBuilder::locationKit(), $context);

    expect($result->earned)->toBeFalse()
        ->and($result->proofEntityCount)->toBe(0);
});

test('the thin-page guard passes when market-tagged reviews exist', function () {
    $backed = PageBuilder::backedSite();

    $result = app(ThinPageGuard::class)->evaluate(
        PageBuilder::locationKit(),
        PageBuilder::context($backed),
    );

    expect($result->earned)->toBeTrue()
        ->and($result->proofEntityCount)->toBeGreaterThan(0);
});
