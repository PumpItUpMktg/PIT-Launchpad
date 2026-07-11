<?php

use App\PageBuilder\Template\KitTemplateGenerator;
use App\PageBuilder\Template\KitTemplateVerifier;
use Tests\Support\PageBuilder;

/**
 * The code-controlled binding loop: the generator emits a fully-bound Elementor
 * template from the kit map, and the verifier proves every required slot is bound.
 * Pure structure — no Elementor runtime — so the only thing left to confirm live is
 * Elementor's import acceptance (the one round-trip).
 *
 * The generator groups slots into designed zones (each its own section), so widgets
 * are spread across sections/columns — these helpers flatten the tree to assert on
 * the widgets wherever they live.
 */
function requiredSlotKeys(): array
{
    $keys = [];
    foreach (PageBuilder::legacyServiceKit()->slots as $slot) {
        if ($slot->isRequired()) {
            $keys[] = $slot->key;
        }
    }

    return $keys;
}

/**
 * Every widget element in the template, depth-first across all sections/columns.
 *
 * @return list<array<string, mixed>>
 */
function allWidgets(array $template): array
{
    $widgets = [];
    $walk = function (array $elements) use (&$walk, &$widgets): void {
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            if (($el['elType'] ?? null) === 'widget') {
                $widgets[] = $el;
            }
            if (is_array($el['elements'] ?? null)) {
                $walk($el['elements']);
            }
        }
    };
    $walk($template['content']);

    return $widgets;
}

it('generates a NATIVE template that binds every required service-kit slot', function () {
    $kit = PageBuilder::legacyServiceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'native');

    $result = app(KitTemplateVerifier::class)->verify($kit, $template);

    expect($result->passes())->toBeTrue()
        ->and($result->missingRequired)->toBe([])
        ->and($result->boundSlots)->toEqualCanonicalizing(
            collect($kit->slots)->pluck('key')->all() // every slot, required or not, is bound
        );

    // Round-trips as JSON (it's the stored artifact).
    expect(json_encode($template))->toBeString();

    // Each widget carries its wf-<slot> marker (across all zone sections).
    $widgets = allWidgets($template);
    $classes = array_map(fn ($w) => $w['settings']['_css_classes'], $widgets);
    expect($classes)->toContain('wf-hero_problem', 'wf-cta', 'wf-contact_block', 'wf-hero_image');

    // Brand layer: widgets reference the Global Kit (system globals), not hardcoded styles.
    $byClass = collect($widgets)->keyBy(fn ($w) => $w['settings']['_css_classes']);
    expect($byClass['wf-hero_problem']['settings']['__globals__'])->toMatchArray([
        'title_color' => 'globals/colors?id=primary',
        'typography_typography' => 'globals/typography?id=primary',
    ])
        ->and($byClass['wf-problem_explainer']['settings']['__globals__'])->toMatchArray([
            'text_color' => 'globals/colors?id=text',
        ])
        ->and($byClass['wf-hero_image']['settings'])->not->toHaveKey('__globals__'); // image: no color/typography
});

it('groups slots into distinct, full-width designed zones', function () {
    $kit = PageBuilder::legacyServiceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'native');

    $sections = $template['content'];
    $zoneClasses = array_map(fn ($s) => $s['settings']['_css_classes'], $sections);

    // More than one section (no longer a single column), and the expected zones.
    expect(count($sections))->toBeGreaterThan(1)
        ->and($zoneClasses)->toContain('lp-zone lp-zone--hero')
        ->and($zoneClasses)->toContain('lp-zone lp-zone--features')
        ->and($zoneClasses)->toContain('lp-zone lp-zone--faq')
        ->and($zoneClasses)->toContain('lp-zone lp-zone--cta');

    // Every section is stretched full-width with an explicit boxed content width.
    foreach ($sections as $section) {
        expect($section['settings']['stretch_section'])->toBe('section-stretched')
            ->and($section['settings']['content_width']['size'])->toBeGreaterThan(0);
    }

    // Width VARIATION: the readable explainer is narrower than the wide feature grid.
    $byZone = collect($sections)->keyBy(fn ($s) => $s['settings']['_css_classes']);
    expect($byZone['lp-zone lp-zone--explainer']['settings']['content_width']['size'])
        ->toBeLessThan($byZone['lp-zone lp-zone--features']['settings']['content_width']['size']);
});

it('lays the hero out as a two-up (copy + image) column split', function () {
    $kit = PageBuilder::legacyServiceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'native');

    $hero = collect($template['content'])->firstWhere('settings._css_classes', 'lp-zone lp-zone--hero');
    $columns = $hero['elements'];

    expect($columns)->toHaveCount(2)
        ->and($columns[0]['settings']['_column_size'])->toBe(60)
        ->and($columns[1]['settings']['_column_size'])->toBe(40);

    // Image lives in the media column; the heading in the copy column.
    $mediaClasses = array_map(fn ($w) => $w['settings']['_css_classes'], $columns[1]['elements']);
    expect($mediaClasses)->toContain('wf-hero_image');
});

it('generates a SHORTCODE template (proven fallback) that also binds every required slot', function () {
    $kit = PageBuilder::legacyServiceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'shortcode');

    expect(app(KitTemplateVerifier::class)->verify($kit, $template)->passes())->toBeTrue();

    $widgets = collect(allWidgets($template));
    $hero = $widgets->firstWhere('settings._css_classes', 'wf-hero_problem');
    expect($hero['widgetType'])->toBe('shortcode')
        ->and($hero['settings']['shortcode'])->toBe('[lp_slot key="hero_problem"]');

    $features = $widgets->firstWhere('settings._css_classes', 'wf-service_features');
    expect($features['settings']['shortcode'])->toBe('[lp_repeater key="service_features"]');
});

it('native cta binds the lp-cta tag and hero_image binds lp-image on an image widget', function () {
    $kit = PageBuilder::legacyServiceKit();
    $widgets = collect(allWidgets(app(KitTemplateGenerator::class)->generate($kit, 'native')));

    $cta = $widgets->firstWhere('settings._css_classes', 'wf-cta');
    expect($cta['settings']['__dynamic__']['editor'])->toContain('name="lp-cta"')
        ->and(urldecode($cta['settings']['__dynamic__']['editor']))->toContain('"slot":"cta"');

    $image = $widgets->firstWhere('settings._css_classes', 'wf-hero_image');
    expect($image['widgetType'])->toBe('image')
        ->and($image['settings']['__dynamic__']['image'])->toContain('name="lp-image"');
});

it('flags a required slot whose binding was removed (guards designer restyle drift)', function () {
    $kit = PageBuilder::legacyServiceKit();
    $template = app(KitTemplateGenerator::class)->generate($kit, 'native');

    // Simulate a designer deleting the hero_problem widget on restyle — strip it
    // wherever it lives in the zone tree.
    $strip = function (array &$elements) use (&$strip): void {
        foreach ($elements as &$el) {
            if (is_array($el) && is_array($el['elements'] ?? null)) {
                $strip($el['elements']);
            }
        }
        unset($el);

        $elements = array_values(array_filter(
            $elements,
            fn ($el) => ! (is_array($el) && ($el['settings']['_css_classes'] ?? '') === 'wf-hero_problem'),
        ));
    };
    $strip($template['content']);

    $result = app(KitTemplateVerifier::class)->verify($kit, $template);

    expect($result->passes())->toBeFalse()
        ->and($result->missingRequired)->toContain('hero_problem');
});
