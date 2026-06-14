<?php

use App\PageBuilder\Library\LibraryServiceComposer;

function serviceSlotsFixture(): array
{
    return [
        'hero_problem' => 'Water heater leaking?',
        'hero_solution' => 'Same-day repair, guaranteed.',
        'problem_explainer' => '<p>A failing heater floods the garage and spikes the bill.</p>',
        'solution_overview' => '<p>Our licensed techs fix it fast, flat-rate.</p>',
        'why_us' => '<p>Family-owned, thousands of local installs, fully warrantied.</p>',
        'proof_strip' => [
            ['value' => '20+', 'label' => 'Years'],
            ['value' => '4.9★', 'label' => 'Rating'],
        ],
        'testimonial' => [
            ['quote' => 'Fast and tidy.', 'author' => 'Pat M.'],
            ['quote' => 'Saved us in a pinch.', 'author' => 'Jo R.'],
        ],
        'faq' => [
            ['question' => '**How long does it take?**', 'answer' => 'Same <strong>day</strong>.'],
            ['question' => 'Do you finance?', 'answer' => 'Yes, 0% APR.'],
        ],
        'cta' => ['type' => 'conversion_block', 'tel' => 'tel:+15551234567', 'call_label' => 'Call Now', 'phone' => '(555) 123-4567'],
    ];
}

function flattenTree(array $elements): array
{
    $all = [];
    $walk = function (array $els) use (&$walk, &$all): void {
        foreach ($els as $el) {
            if (! is_array($el)) {
                continue;
            }
            $all[] = $el;
            if (! empty($el['elements'])) {
                $walk($el['elements']);
            }
        }
    };
    $walk($elements);

    return $all;
}

function byHook(array $tree, string $hook): ?array
{
    foreach (flattenTree($tree) as $el) {
        $cls = $el['settings']['_css_classes'] ?? '';
        if (is_string($cls) && in_array($hook, explode(' ', $cls), true)) {
            return $el;
        }
    }

    return null;
}

it('assembles the service body in order, dropping jobs + proof-strip (no §3a source)', function () {
    $doc = app(LibraryServiceComposer::class)->compose(serviceSlotsFixture(), [
        'hero_image' => ['url' => 'https://r2.example/hero.jpg'],
    ]);

    $blocks = [];
    foreach (flattenTree($doc) as $el) {
        $cls = $el['settings']['_css_classes'] ?? '';
        foreach (explode(' ', is_string($cls) ? $cls : '') as $c) {
            if (str_starts_with($c, 'wf-block-')) {
                $blocks[] = $c;
            }
        }
    }

    expect($blocks)->toBe([
        'wf-block-hero',
        'wf-block-trust-bar',
        'wf-block-problem-solution',
        'wf-block-why-us',
        'wf-block-testimonials',
        'wf-block-faq',
        'wf-block-final-cta',
    ]); // jobs + proof-strip dropped
});

it('injects fed hooks and rebuilds the faq as a nested-accordion (plainTitle questions)', function () {
    $doc = app(LibraryServiceComposer::class)->compose(serviceSlotsFixture(), [
        'hero_image' => ['url' => 'https://r2.example/hero.jpg'],
    ]);

    expect(byHook($doc, 'wf-hero-headline')['settings']['title'])->toBe('Water heater leaking?')
        ->and(byHook($doc, 'wf-hero-subhead')['settings']['editor'])->toBe('Same-day repair, guaranteed.')
        ->and(byHook($doc, 'wf-hero-image')['settings']['image']['url'])->toBe('https://r2.example/hero.jpg')
        ->and(byHook($doc, 'wf-ps-problem-body')['settings']['editor'])->toContain('floods the garage')
        ->and(byHook($doc, 'wf-trust-value-1')['settings']['title'])->toBe('20+')
        ->and(byHook($doc, 'wf-trust-label-1')['settings']['editor'])->toBe('Years')
        ->and(byHook($doc, 'wf-review-2-body')['settings']['editor'])->toBe('Saved us in a pinch.');

    $faq = byHook($doc, 'wf-faq');
    expect($faq['widgetType'])->toBe('nested-accordion')
        ->and($faq['settings']['items'])->toHaveCount(2)
        ->and($faq['settings']['items'][0]['item_title'])->toBe('How long does it take?') // ** stripped
        ->and($faq['elements'][0]['isLocked'])->toBeTrue();

    // CTA → a real button with a tel: link.
    $cta = byHook($doc, 'wf-cta-primary');
    expect($cta['settings']['text'])->toBe('Call Now')
        ->and($cta['settings']['link']['url'])->toBe('tel:+15551234567');
});

it('hides unfed per-tenant hooks but keeps static section headings', function () {
    $doc = app(LibraryServiceComposer::class)->compose(serviceSlotsFixture(), [
        'hero_image' => ['url' => 'https://r2.example/hero.jpg'],
    ]);

    // Unfed content hooks are gone.
    expect(byHook($doc, 'wf-hero-eyebrow'))->toBeNull()
        ->and(byHook($doc, 'wf-trust-value-3'))->toBeNull()   // only 2 stats fed (truncate/hide)
        ->and(byHook($doc, 'wf-review-3-body'))->toBeNull()   // only 2 reviews
        ->and(byHook($doc, 'wf-why-card-2-body'))->toBeNull() // why_us → card 1 only
        ->and(byHook($doc, 'wf-why-card-1-title'))->toBeNull(); // card title unfed → hidden

    // Static section headings stay (design chrome).
    expect(byHook($doc, 'wf-why-heading')['settings']['title'])->toBe('Why choose us')
        ->and(byHook($doc, 'wf-faq-heading'))->not->toBeNull();
});

it('never ships a library placeholder to a live page', function () {
    $json = json_encode(app(LibraryServiceComposer::class)->compose(serviceSlotsFixture(), [
        'hero_image' => ['url' => 'https://r2.example/hero.jpg'],
    ]));

    expect($json)->not->toContain('PLACEHOLDER.local')
        ->and($json)->not->toContain('Reason 1')
        ->and($json)->not->toContain('Value 1')
        ->and($json)->not->toContain('Frequently asked question 1')
        ->and($json)->not->toContain('Supporting copy');
});

it('keeps the hero a two-column (copy + image) row', function () {
    $doc = app(LibraryServiceComposer::class)->compose(serviceSlotsFixture(), [
        'hero_image' => ['url' => 'https://r2.example/hero.jpg'],
    ]);

    // A row container survives inside the hero block (the 2-up), and it still holds
    // both the headline and the image.
    $row = collect(flattenTree($doc))->first(fn ($el) => ($el['elType'] ?? '') === 'container'
        && (($el['settings']['flex_direction'] ?? '') === 'row'));
    expect($row)->not->toBeNull();
    expect(byHook($doc, 'wf-hero-image'))->not->toBeNull();
});
