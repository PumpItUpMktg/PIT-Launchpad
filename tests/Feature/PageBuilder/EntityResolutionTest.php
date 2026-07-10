<?php

use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;
use App\PageBuilder\Validation\KitValidator;
use App\PageBuilder\Validation\ValidationContext;
use Tests\Support\PageBuilder;

/**
 * Builds a site with every entity the kits need EXCEPT substantiated proof and
 * reviews, so grounded/entity proof slots are the only ones that fail.
 *
 * @return array{context: ValidationContext}
 */
function siteWithoutProof(): array
{
    $site = Site::factory()->create();
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);
    ConversionConfig::factory()->create(['site_id' => $site->id, 'primary_actions' => ['call']]);
    Location::factory()->create(['site_id' => $site->id, 'is_storefront' => true]);
    Service::factory()->count(2)->create(['site_id' => $site->id]);
    $content = Content::factory()->create(['site_id' => $site->id]);

    return ['context' => new ValidationContext($content, $market, ['is_storefront' => true])];
}

test('the grounded why_us slot is OMITTED (not failed) when a site has no substantiated proof', function () {
    // §3a policy: why_us is conditional on has_proof — with no substantiated proof
    // the section omits rather than failing the page (Eric: conditional-omit, not block).
    // siteWithoutProof()'s context carries no has_proof flag, so the slot doesn't apply.
    ['context' => $context] = siteWithoutProof();

    $result = app(KitValidator::class)->validate(
        PageBuilder::serviceKit(),
        PageBuilder::validServicePayload(),
        $context,
    );

    expect($result->failuresFor('why_us'))->toBe([]); // omitted, never an EntityBelowMinimum block
});

test('the block-era location kit stakes no grounded slots — it validates without proof', function () {
    // The old kit's why_us_local (grounded) is gone: local reviews/jobs are provider-gated page
    // SECTIONS (empty => omitted by the composer), so proof never blocks a location draft.
    ['context' => $context] = siteWithoutProof();

    $result = app(KitValidator::class)->validate(
        PageBuilder::locationKit(),
        PageBuilder::validLocationPayload(),
        $context,
    );

    expect($result->passed())->toBeTrue();
});

test('grounded proof slots pass once substantiated proof exists', function () {
    $backed = PageBuilder::backedSite();

    $result = app(KitValidator::class)->validate(
        PageBuilder::serviceKit(),
        PageBuilder::validServicePayload(),
        PageBuilder::context($backed),
    );

    expect($result->failuresFor('why_us'))->toBe([])
        ->and($result->passed())->toBeTrue();
});
