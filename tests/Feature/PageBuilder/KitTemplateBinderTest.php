<?php

use App\PageBuilder\Template\KitTemplateBinder;
use App\PageBuilder\Template\KitTemplateVerifier;
use Tests\Support\PageBuilder;

/**
 * The production binding path: attach lp/* bindings to the DESIGNER's own styled
 * template by wf-<slot> marker, preserving their layout/styling and leaving
 * unmarked (decorative) and unmappable widgets alone.
 */
function widget(string $type, string $css, array $extra = []): array
{
    return [
        'id' => substr(md5($css.$type), 0, 7),
        'elType' => 'widget',
        'widgetType' => $type,
        'elements' => [],
        'settings' => array_merge(['_css_classes' => $css], $extra),
    ];
}

function designerTemplate(): array
{
    return [
        'version' => '0.4',
        'title' => 'Single Page – Service',
        'type' => 'section',
        'content' => [[
            'id' => 'sec0001',
            'elType' => 'section',
            'settings' => ['background_color' => '#fafafa'],
            'elements' => [[
                'id' => 'col0001',
                'elType' => 'column',
                'settings' => ['_column_size' => 100],
                'elements' => [
                    widget('heading', 'wf-hero_problem brand-h1', ['title' => 'designer placeholder', 'title_color' => '#111', 'typography_font_size' => ['size' => 48]]),
                    widget('text-editor', 'wf-problem_explainer'),
                    widget('image', 'wf-hero_image'),
                    widget('text-editor', 'wf-faq'),
                    widget('icon-list', 'wf-service_features'),        // unmapped widget type
                    widget('icon-box', 'brand-feature-card', ['title' => 'Fancy decorative']), // no wf — untouched
                ],
            ]],
        ]],
    ];
}

function boundWidgets(array $template): array
{
    $widgets = [];
    foreach ($template['content'][0]['elements'][0]['elements'] as $w) {
        $widgets[$w['settings']['_css_classes']] = $w;
    }

    return $widgets;
}

it('injects lp/* bindings onto the designer widgets by wf-<slot>, on the right controls', function () {
    $bound = app(KitTemplateBinder::class)->bind(PageBuilder::legacyServiceKit(), designerTemplate());
    $w = boundWidgets($bound);

    $heading = $w['wf-hero_problem brand-h1']['settings'];
    expect($heading['__dynamic__']['title'])->toContain('name="lp-text"')
        ->and(urldecode($heading['__dynamic__']['title']))->toContain('"slot":"hero_problem"');

    expect($w['wf-problem_explainer']['settings']['__dynamic__']['editor'])->toContain('name="lp-text"')
        ->and($w['wf-hero_image']['settings']['__dynamic__']['image'])->toContain('name="lp-image"')
        ->and($w['wf-faq']['settings']['__dynamic__']['editor'])->toContain('name="lp-repeater"');
});

it('preserves the designer styling and leaves decorative + unmapped widgets untouched', function () {
    $bound = app(KitTemplateBinder::class)->bind(PageBuilder::legacyServiceKit(), designerTemplate());
    $w = boundWidgets($bound);

    // Bound heading keeps every authored style setting.
    $heading = $w['wf-hero_problem brand-h1']['settings'];
    expect($heading['title'])->toBe('designer placeholder')
        ->and($heading['title_color'])->toBe('#111')
        ->and($heading['typography_font_size'])->toBe(['size' => 48]);

    // Section styling preserved.
    expect($bound['content'][0]['settings']['background_color'])->toBe('#fafafa');

    // Decorative (no wf) widget — completely untouched, no binding added.
    expect($w['brand-feature-card']['settings'])->not->toHaveKey('__dynamic__')
        ->and($w['brand-feature-card']['settings']['title'])->toBe('Fancy decorative');

    // Unmappable widget type (icon-list) — left unbound, never guessed.
    expect($w['wf-service_features']['settings'])->not->toHaveKey('__dynamic__');
});

it('verifier reports the bound slots and flags the unmapped (icon-list) required slot', function () {
    $kit = PageBuilder::legacyServiceKit();
    $bound = app(KitTemplateBinder::class)->bind($kit, designerTemplate());

    $result = app(KitTemplateVerifier::class)->verify($kit, $bound);

    expect($result->boundSlots)->toContain('hero_problem', 'problem_explainer', 'hero_image', 'faq')
        ->and($result->missingRequired)->toContain('service_features'); // icon-list wasn't bindable
});
