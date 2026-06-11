<?php

use App\Operator\Controls\TemplateMapping;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\RenderCoordinator;
use Tests\Support\PublishHarness;

test('the assembled meta-blob matches the companion-plugin /content contract', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    $outcome = app(RenderCoordinator::class)->render($content);
    $payload = app(MetaBlobAssembler::class)->assemble($content, $outcome->jobs);

    // Top-level contract keys (per samples/content-service.json).
    expect($payload)->toHaveKeys([
        'content_id', 'kind', 'page_type', 'kit', 'kit_version',
        'silo_id', 'slug', 'status', 'locked', 'slot_payload', 'kit_definition', 'template_id', 'images', 'seo',
    ]);

    // The trimmed kit contract travels with the push (feeds the plugin's
    // Slots & Shortcodes reference): key / label / content_type / cardinality / required.
    expect($payload['kit_definition'])->toBeArray()->not->toBeEmpty();
    $hero = collect($payload['kit_definition'])->firstWhere('key', 'hero_problem');
    expect($hero)->not->toBeNull()
        ->and($hero)->toHaveKeys(['key', 'label', 'content_type', 'cardinality', 'required'])
        ->and($hero['required'])->toBeTrue();
    $features = collect($payload['kit_definition'])->firstWhere('key', 'service_features');
    expect($features['cardinality']['type'])->toBe('repeater');

    expect($payload['content_id'])->toBe($content->id)
        ->and($payload['kind'])->toBe('page')
        ->and($payload['page_type'])->toBe('service')
        ->and($payload['kit'])->toBe('service-page')
        ->and($payload['kit_version'])->toBe('1')
        ->and($payload['status'])->toBe('published')
        ->and($payload['locked'])->toBeFalse();

    // Slots pass through keyed by slot key — the plugin's dynamic tags read them.
    expect($payload['slot_payload']['hero_problem'])->toContain('Leaking')
        ->and($payload['slot_payload']['service_features'])->toBeArray();

    // Image map keyed by slot, with the R2 URL the lp/image tag renders.
    expect($payload['images']['hero_image'])->toHaveKey('url')
        ->and($payload['images']['hero_image']['alt'])->not->toBeEmpty();

    // Engine-owned SEO, with canonical + the OG image driven by the kit's
    // og_image seo_binding (the hero).
    expect($payload['seo'])->toHaveKeys(['title', 'meta_description', 'canonical', 'robots', 'og', 'schema_type', 'breadcrumbs'])
        ->and($payload['seo']['title'])->toBe('Water Heater Repair in Austin | Apex')
        ->and($payload['seo']['canonical'])->toBe('https://apex.example/water-heater-repair-austin')
        ->and($payload['seo']['og']['image'])->toBe($payload['images']['hero_image']['url'])
        ->and($payload['seo']['schema_type'])->toBe('Service');
});

test('the blob carries the operator-mapped template id for the page kit (null when unmapped)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);
    $outcome = app(RenderCoordinator::class)->render($content);

    // Unmapped → null (the plugin falls back to the kit's suggestion).
    expect(app(MetaBlobAssembler::class)->assemble($content, $outcome->jobs)['template_id'])->toBeNull();

    // Map the page's kit (service-page) → an Elementor template id; it rides the blob.
    app(TemplateMapping::class)->map($site, 'service-page', 77, 'Service Template');

    expect(app(MetaBlobAssembler::class)->assemble($content, $outcome->jobs)['template_id'])->toBe(77);
});
