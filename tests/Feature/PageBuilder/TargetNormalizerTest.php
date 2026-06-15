<?php

use App\PageBuilder\Library\BlockLibrary;
use App\PageBuilder\Library\TargetNormalizer;

/** Find the first container carrying the `wf-block` class anywhere in a tree. */
function findWfBlock(array $elements): ?array
{
    foreach ($elements as $el) {
        $classes = $el['settings']['_css_classes'] ?? '';
        if (is_string($classes) && in_array('wf-block', explode(' ', $classes), true)) {
            return $el;
        }
        if (! empty($el['elements'])) {
            $found = findWfBlock($el['elements']);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/** Find the first widget of a type anywhere in an element tree. */
function findWidget(array $elements, string $type): ?array
{
    foreach ($elements as $el) {
        if (($el['elType'] ?? null) === 'widget' && ($el['widgetType'] ?? null) === $type) {
            return $el;
        }
        if (! empty($el['elements'])) {
            $found = findWidget($el['elements'], $type);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

it('loads the real library block-faq as a classic accordion', function () {
    $faq = (new BlockLibrary)->block('faq');

    $accordion = findWidget($faq, 'accordion');
    expect($accordion)->not->toBeNull()
        ->and($accordion['settings']['tabs'])->toBeArray()->not->toBeEmpty()
        ->and($accordion['settings']['_css_classes'])->toBe('wf-faq');
});

it('normalizes the classic accordion into the verified nested-accordion shape', function () {
    $faq = (new BlockLibrary)->block('faq');
    $classic = findWidget($faq, 'accordion');
    $tabCount = count($classic['settings']['tabs']);

    $normalized = (new TargetNormalizer)->normalize($faq);

    // No classic accordion remains; a nested-accordion took its place.
    expect(findWidget($normalized, 'accordion'))->toBeNull();
    $nested = findWidget($normalized, 'nested-accordion');
    expect($nested)->not->toBeNull();

    // Titles in items[] (1:1 with the old tabs); wf-faq hook preserved.
    expect($nested['settings']['items'])->toHaveCount($tabCount)
        ->and($nested['settings']['items'][0]['item_title'])->toBe($classic['settings']['tabs'][0]['tab_title'])
        ->and($nested['settings']['_css_classes'])->toBe('wf-faq');

    // One index-paired, LOCKED child container per item → inner column → text-editor.
    expect($nested['elements'])->toHaveCount($tabCount);
    $panel = $nested['elements'][0];
    expect($panel['elType'])->toBe('container')
        ->and($panel['isInner'])->toBeTrue()
        ->and($panel['isLocked'])->toBeTrue()
        ->and($panel['settings']['content_width'])->toBe('full');

    $inner = $panel['elements'][0];
    expect($inner['settings']['flex_direction'])->toBe('column');

    $textEditor = $inner['elements'][0];
    expect($textEditor['widgetType'])->toBe('text-editor')
        ->and($textEditor['settings']['editor'])->toBe($classic['settings']['tabs'][0]['tab_content']);
});

it('passes through the faq heading and other widgets unchanged', function () {
    $normalized = (new TargetNormalizer)->normalize((new BlockLibrary)->block('faq'));

    $heading = findWidget($normalized, 'heading');
    expect($heading['settings']['_css_classes'])->toBe('wf-faq-heading')
        ->and($heading['settings']['title'])->toBe('Frequently asked questions');
});

it('strips the baked padding off wf-block containers (structure owns density)', function () {
    $hero = (new BlockLibrary)->block('hero');

    // The library bakes padding on the wf-block container.
    $block = findWfBlock($hero);
    expect($block['settings']['padding'] ?? null)->not->toBeNull();

    $normalized = (new TargetNormalizer)->normalize($hero);

    // After normalize the wf-block padding is gone — the base wf-* stylesheet's
    // --wf-pad-block drives it now — but everything else (gaps, classes) survives.
    $normBlock = findWfBlock($normalized);
    expect($normBlock['settings'])->not->toHaveKey('padding')
        ->and($normBlock['settings']['_css_classes'])->toBe('wf-block wf-block-hero')
        ->and($normBlock['settings']['flex_gap'])->toBe($block['settings']['flex_gap']);
});

it('only strips padding from wf-block containers, never inner ones', function () {
    $tree = [[
        'elType' => 'container',
        'settings' => ['_css_classes' => 'wf-block wf-block-x', 'padding' => ['top' => '64']],
        'elements' => [[
            'elType' => 'container',
            'settings' => ['padding' => ['top' => '20']], // inner, no wf-block class
            'elements' => [],
        ]],
    ]];

    $out = (new TargetNormalizer)->normalize($tree);

    expect($out[0]['settings'])->not->toHaveKey('padding')              // wf-block stripped
        ->and($out[0]['elements'][0]['settings']['padding'])->toBe(['top' => '20']); // inner kept
});

it('keeps the baked padding when strip_block_padding is off', function () {
    $hero = (new BlockLibrary)->block('hero');

    $normalized = (new TargetNormalizer(['strip_block_padding' => false]))->normalize($hero);

    expect(findWfBlock($normalized)['settings'])->toHaveKey('padding');
});

it('exposes the full block roster', function () {
    expect((new BlockLibrary)->blockNames())->toContain('hero', 'faq', 'services-grid', 'final-cta')
        ->and(count((new BlockLibrary)->blockNames()))->toBeGreaterThanOrEqual(26);
});
