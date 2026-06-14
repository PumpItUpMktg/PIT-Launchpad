<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Operator\Controls\TemplateMapping;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\RenderCoordinator;
use Illuminate\Support\Collection;
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

    // The featured image the plugin sets as the WP post thumbnail = the og/hero image.
    expect($payload['featured_image'])->toBe($payload['images']['hero_image']['url']);

    // Engine-owned SEO, with canonical + the OG image driven by the kit's
    // og_image seo_binding (the hero).
    // The SEO title is normalized — the "| Apex" branding suffix is stripped.
    expect($payload['seo'])->toHaveKeys(['title', 'meta_description', 'canonical', 'robots', 'og', 'schema_type', 'breadcrumbs'])
        ->and($payload['seo']['title'])->toBe('Water Heater Repair in Austin')
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

test('the meta-blob carries a native elementor_data body for a page AND retains slot_payload for schema (send-half)', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();
    $content = PublishHarness::approvedPage($site);

    // Add a faq so we can prove the FAQPage schema SOURCE survives the native swap.
    $content->slot_payload = array_merge($content->slot_payload, [
        'faq' => [['question' => 'How long does it take?', 'answer' => 'Same <strong>day</strong>.']],
    ]);
    $content->save();

    $outcome = app(RenderCoordinator::class)->render($content);
    $payload = app(MetaBlobAssembler::class)->assemble($content->fresh(), $outcome->jobs);

    // Native body present, container-based zones (hero ... faq).
    expect($payload['elementor_data'])->toBeArray()->not->toBeEmpty();
    $classes = array_map(fn ($z) => $z['settings']['_css_classes'] ?? '', $payload['elementor_data']);
    expect($classes)->toContain('lp-zone lp-zone--hero')
        ->and($classes)->toContain('lp-zone lp-zone--faq');

    // FAQ is a native accordion baked from the slot...
    $faqZone = collect($payload['elementor_data'])->firstWhere('settings._css_classes', 'lp-zone lp-zone--faq');
    $accordion = $faqZone['elements'][0];
    expect($accordion['widgetType'])->toBe('nested-accordion')
        ->and($accordion['settings']['items'][0]['item_title'])->toBe('How long does it take?');

    // ...AND the faq slot is RETAINED in slot_payload — the FAQPage JSON-LD source the
    // plugin reads (schema survival, verified not assumed), with SEO still travelling.
    expect($payload['slot_payload']['faq'])->toBe([
        ['question' => 'How long does it take?', 'answer' => 'Same <strong>day</strong>.'],
    ])->and($payload['seo'])->toHaveKey('schema_type');
});

test('a post carries NO native body — the single-post template renders it', function () {
    PublishHarness::fakeAdapters();
    $site = PublishHarness::site();

    $post = Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Post,
        'status' => ContentStatus::Approved,
        'body' => 'A reactive news post body.',
    ]);

    $payload = app(MetaBlobAssembler::class)->assemble($post->fresh(), new Collection);

    expect($payload['elementor_data'])->toBe([]);              // posts → plugin no-op
});
