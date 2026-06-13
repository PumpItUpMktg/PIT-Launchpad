<?php

use App\PageBuilder\Template\KitTemplateGenerator;
use App\PageBuilder\Template\KitTemplateVerifier;
use Tests\Support\PageBuilder;

/**
 * The code-controlled binding loop: the generator emits a fully-bound Elementor
 * template from the kit map, and the verifier proves every required slot is bound.
 * Pure structure — no Elementor runtime — so the only thing left to confirm live is
 * Elementor's import acceptance (the one round-trip).
 */
function requiredSlotKeys(): array
{
    $keys = [];
    foreach (PageBuilder::serviceKit()->slots as $slot) {
        if ($slot->isRequired()) {
            $keys[] = $slot->key;
        }
    }

    return $keys;
}

it('generates a NATIVE template that binds every required service-kit slot', function () {
    $kit = PageBuilder::serviceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'native');

    $result = app(KitTemplateVerifier::class)->verify($kit, $template);

    expect($result->passes())->toBeTrue()
        ->and($result->missingRequired)->toBe([])
        ->and($result->boundSlots)->toEqualCanonicalizing(
            collect($kit->slots)->pluck('key')->all() // every slot, required or not, is bound
        );

    // Round-trips as JSON (it's the stored artifact).
    expect(json_encode($template))->toBeString();

    // Each widget carries its wf-<slot> marker.
    $section = $template['content'][0];
    $widgets = $section['elements'][0]['elements'];
    $classes = array_map(fn ($w) => $w['settings']['_css_classes'], $widgets);
    expect($classes)->toContain('wf-hero_problem', 'wf-cta', 'wf-contact_block', 'wf-hero_image');
});

it('generates a SHORTCODE template (proven fallback) that also binds every required slot', function () {
    $kit = PageBuilder::serviceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'shortcode');

    expect(app(KitTemplateVerifier::class)->verify($kit, $template)->passes())->toBeTrue();

    $widgets = $template['content'][0]['elements'][0]['elements'];
    $hero = collect($widgets)->firstWhere('settings._css_classes', 'wf-hero_problem');
    expect($hero['widgetType'])->toBe('shortcode')
        ->and($hero['settings']['shortcode'])->toBe('[lp_slot key="hero_problem"]');

    $features = collect($widgets)->firstWhere('settings._css_classes', 'wf-service_features');
    expect($features['settings']['shortcode'])->toBe('[lp_repeater key="service_features"]');
});

it('native cta binds the lp-cta tag and hero_image binds lp-image on an image widget', function () {
    $kit = PageBuilder::serviceKit();
    $widgets = app(KitTemplateGenerator::class)->generate($kit, 'native')['content'][0]['elements'][0]['elements'];

    $cta = collect($widgets)->firstWhere('settings._css_classes', 'wf-cta');
    expect($cta['settings']['__dynamic__']['editor'])->toContain('name="lp-cta"')
        ->and(urldecode($cta['settings']['__dynamic__']['editor']))->toContain('"slot":"cta"');

    $image = collect($widgets)->firstWhere('settings._css_classes', 'wf-hero_image');
    expect($image['widgetType'])->toBe('image')
        ->and($image['settings']['__dynamic__']['image'])->toContain('name="lp-image"');
});

it('flags a required slot whose binding was removed (guards designer restyle drift)', function () {
    $kit = PageBuilder::serviceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'native');

    // Simulate a designer deleting the hero_problem widget on restyle.
    $template['content'][0]['elements'][0]['elements'] = array_values(array_filter(
        $template['content'][0]['elements'][0]['elements'],
        fn ($w) => ($w['settings']['_css_classes'] ?? '') !== 'wf-hero_problem',
    ));

    $result = app(KitTemplateVerifier::class)->verify($kit, $template);

    expect($result->passes())->toBeFalse()
        ->and($result->missingRequired)->toContain('hero_problem');
});
