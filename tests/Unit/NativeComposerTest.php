<?php

use App\PageBuilder\Native\NativeComposer;

function faqInput(): array
{
    return [
        ['question' => 'How long does install take?', 'answer' => 'Most installs are <strong>same-day</strong>.'],
        ['question' => 'Will it lower my bills?', 'answer' => 'Yes — up to <strong>40%</strong>.'],
    ];
}

it('emits a nested-accordion with titles in items[] in order', function () {
    $acc = (new NativeComposer)->faqAccordion(faqInput());

    expect($acc['widgetType'])->toBe('nested-accordion')
        ->and(array_column($acc['settings']['items'], 'item_title'))->toBe([
            'How long does install take?',
            'Will it lower my bills?',
        ]);
});

it('pairs each title to a locked, full-width child container BY INDEX', function () {
    $acc = (new NativeComposer)->faqAccordion(faqInput());

    expect($acc['settings']['items'])->toHaveCount(2)
        ->and($acc['elements'])->toHaveCount(2); // one panel per item, same order

    foreach ($acc['elements'] as $panel) {
        expect($panel['elType'])->toBe('container')
            ->and($panel['isLocked'])->toBeTrue()
            ->and($panel['settings']['content_width'])->toBe('full');
    }
});

it('puts each answer in a text-editor inside the matching panel (prose stays text-editor)', function () {
    $acc = (new NativeComposer)->faqAccordion(faqInput());

    // panel[1] (second item) → inner container → text-editor with the 2nd answer.
    $textEditor = $acc['elements'][1]['elements'][0]['elements'][0];

    expect($textEditor['widgetType'])->toBe('text-editor')
        ->and($textEditor['settings']['editor'])->toBe('Yes — up to <strong>40%</strong>.');
});

it('skips blank-question items and returns null when nothing remains', function () {
    $composer = new NativeComposer;

    $acc = $composer->faqAccordion([
        ['question' => '   ', 'answer' => 'orphan answer'],
        ['question' => 'Real?', 'answer' => 'Yes.'],
    ]);
    expect($acc['settings']['items'])->toHaveCount(1)
        ->and($acc['settings']['items'][0]['item_title'])->toBe('Real?');

    expect($composer->faqAccordion([]))->toBeNull()
        ->and($composer->faqAccordion([['question' => '', 'answer' => 'x']]))->toBeNull();
});

it('wraps the accordion in a boxed faq zone container (container-based, not section)', function () {
    $doc = (new NativeComposer)->faqDocument(faqInput());

    expect($doc)->toHaveCount(1);
    $zone = $doc[0];

    expect($zone['elType'])->toBe('container')                        // not 'section'
        ->and($zone['settings']['_css_classes'])->toBe('lp-zone lp-zone--faq')
        ->and($zone['settings']['content_width'])->toBe('boxed')
        ->and($zone['settings']['boxed_width']['size'])->toBe(800)
        ->and($zone['elements'][0]['widgetType'])->toBe('nested-accordion');
});

it('returns an empty document when there is no faq', function () {
    expect((new NativeComposer)->faqDocument([]))->toBe([]);
});

it('round-trips as JSON and gives every element a unique, deterministic id', function () {
    $doc = (new NativeComposer)->faqDocument(faqInput());

    expect(json_encode($doc))->toBeString();

    // Collect every element id depth-first.
    $ids = [];
    $walk = function (array $els) use (&$walk, &$ids): void {
        foreach ($els as $el) {
            $ids[] = $el['id'];
            if (! empty($el['elements'])) {
                $walk($el['elements']);
            }
        }
    };
    $walk($doc);

    expect($ids)->toBe(array_unique($ids))                            // all unique
        ->and((new NativeComposer)->faqDocument(faqInput())[0]['id'])->toBe($doc[0]['id']); // deterministic
});
