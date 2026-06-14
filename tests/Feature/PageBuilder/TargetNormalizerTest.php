<?php

use App\PageBuilder\Library\BlockLibrary;
use App\PageBuilder\Library\TargetNormalizer;

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

it('leaves a block with no accordion structurally identical', function () {
    $hero = (new BlockLibrary)->block('hero');

    expect((new TargetNormalizer)->normalize($hero))->toBe($hero);
});

it('exposes the full block roster', function () {
    expect((new BlockLibrary)->blockNames())->toContain('hero', 'faq', 'services-grid', 'final-cta')
        ->and(count((new BlockLibrary)->blockNames()))->toBeGreaterThanOrEqual(26);
});
