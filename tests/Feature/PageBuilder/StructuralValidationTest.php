<?php

use App\PageBuilder\Validation\KitValidator;
use App\PageBuilder\Validation\ValidationCode;
use Tests\Support\PageBuilder;

beforeEach(function () {
    $this->backed = PageBuilder::backedSite();
    $this->context = PageBuilder::context($this->backed);
    $this->kit = PageBuilder::serviceKit();
    $this->validator = app(KitValidator::class);
});

test('a fully valid payload passes for the service kit', function () {
    $result = $this->validator->validate($this->kit, PageBuilder::validServicePayload(), $this->context);

    expect($result->passed())->toBeTrue()
        ->and($result->failures)->toBe([]);
});

test('a missing required slot fails', function () {
    $payload = PageBuilder::validServicePayload();
    unset($payload['hero_problem']);

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->passed())->toBeFalse()
        ->and($result->hasCode(ValidationCode::MissingRequiredSlot))->toBeTrue()
        ->and($result->failuresFor('hero_problem'))->toHaveCount(1);
});

test('text below the minimum length fails', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['hero_problem'] = 'Leak';

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::LengthBelowMinimum))->toBeTrue();
});

test('text above the maximum length fails', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['hero_problem'] = str_repeat('a', 200);

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::LengthAboveMaximum))->toBeTrue();
});

test('a repeater below its minimum cardinality fails', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['service_features'] = ['Only one', 'Only two'];

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::CardinalityBelowMinimum))->toBeTrue();
});

test('a repeater above its maximum cardinality fails', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['service_features'] = array_fill(0, 9, 'Feature');

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::CardinalityAboveMaximum))->toBeTrue();
});

test('a media slot missing alt text fails', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['hero_image'] = ['src' => 'hero.webp', 'width' => 1200, 'height' => 675];

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::MediaAltMissing))->toBeTrue();
});

test('a media slot below the declared size fails', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['hero_image'] = ['src' => 'hero.webp', 'alt' => 'A water heater', 'width' => 400, 'height' => 300];

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::MediaSizeBelowMinimum))->toBeTrue();
});

test('an entity CTA with only a label passes — url/action resolve from §1 at publish, not at draft', function () {
    // The live failure: the model drafted cta/contact_block (content_type=cta) with
    // just a label, and the matcher demanded url|action — rejecting valid copy.
    $payload = PageBuilder::validServicePayload();
    $payload['cta'] = ['label' => 'Call now for same-day service'];
    $payload['contact_block'] = ['label' => 'Contact our team'];

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::ContentTypeMismatch))->toBeFalse()
        ->and($result->failuresFor('cta'))->toBe([])
        ->and($result->failuresFor('contact_block'))->toBe([]);
});

test('a CTA with no label still fails the content-type check', function () {
    $payload = PageBuilder::validServicePayload();
    $payload['cta'] = ['url' => 'https://example.com/book']; // url but no label

    $result = $this->validator->validate($this->kit, $payload, $this->context);

    expect($result->hasCode(ValidationCode::ContentTypeMismatch))->toBeTrue();
});
