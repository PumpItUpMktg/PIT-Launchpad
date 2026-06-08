<?php

use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;
use App\PageBuilder\Validation\KitValidator;
use App\PageBuilder\Validation\ValidationCode;
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

test('the grounded why_us slot fails when a site has no substantiated proof', function () {
    ['context' => $context] = siteWithoutProof();

    $result = app(KitValidator::class)->validate(
        PageBuilder::serviceKit(),
        PageBuilder::validServicePayload(),
        $context,
    );

    expect($result->passed())->toBeFalse()
        ->and($result->hasCode(ValidationCode::EntityBelowMinimum))->toBeTrue();

    $whyUsCodes = array_map(fn ($f) => $f->code, $result->failuresFor('why_us'));
    expect($whyUsCodes)->toContain(ValidationCode::EntityBelowMinimum);
});

test('the grounded why_us_local slot fails when a site has no substantiated proof', function () {
    ['context' => $context] = siteWithoutProof();

    $result = app(KitValidator::class)->validate(
        PageBuilder::locationKit(),
        PageBuilder::validLocationPayload(),
        $context,
    );

    $codes = array_map(fn ($f) => $f->code, $result->failuresFor('why_us_local'));
    expect($codes)->toContain(ValidationCode::EntityBelowMinimum);
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
