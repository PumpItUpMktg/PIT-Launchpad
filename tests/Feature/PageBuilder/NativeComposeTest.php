<?php

use App\PageBuilder\Native\NativeComposer;
use App\PageBuilder\Schema\KitSchema;

function serviceKitSchema(): KitSchema
{
    // The Elementor-era 13-slot shape, frozen as a fixture — NativeComposer is legacy tooling that
    // no longer feeds the (block-era) live service kit.
    $raw = json_decode((string) file_get_contents(base_path('tests/Support/fixtures/legacy-service-kit.json')), true);

    return KitSchema::fromArray($raw);
}

function composedService(): array
{
    $values = [
        'hero_problem' => 'No hot water?',
        'hero_solution' => 'Same-day repair, guaranteed.',
        'problem_explainer' => '<p>A failing heater is expensive.</p>',
        'solution_overview' => '<p>We fix it fast.</p>',
        'service_features' => ['<strong>Endless</strong> hot water', 'Lower bills'],
        'process_steps' => ['Inspect the system', 'Quote the work'],
        'why_us' => '<p>Licensed and insured.</p>',
        'proof_strip' => [['value' => '20+', 'label' => 'years']],         // stat → skipped (Tier-2)
        'testimonial' => [['quote' => 'Great!', 'author' => 'Pat']],       // → skipped (Tier-2)
        'faq' => [['question' => 'How long?', 'answer' => 'Same <strong>day</strong>.']],
        'cta' => ['type' => 'conversion_block', 'tel' => 'tel:+15551234567', 'call_label' => 'Call Now', 'phone' => '(555) 123-4567'],
        'contact_block' => ['type' => 'nap', 'name' => 'Acme', 'phone' => '(555) 123-4567'], // nap → skipped (Tier-2)
    ];
    $images = ['hero_image' => ['url' => 'https://r2.example/hero.jpg', 'alt' => 'heater']];

    return (new NativeComposer)->compose(serviceKitSchema(), $values, $images);
}

/** @return array<string, array<string,mixed>> zone css-class => zone container */
function zonesByClass(array $doc): array
{
    $by = [];
    foreach ($doc as $zone) {
        $by[$zone['settings']['_css_classes']] = $zone;
    }

    return $by;
}

/** Native widgets directly in a zone container (depth-first). */
function zoneWidgets(array $zone): array
{
    $out = [];
    $walk = function (array $els) use (&$walk, &$out): void {
        foreach ($els as $el) {
            if (($el['elType'] ?? null) === 'widget') {
                $out[] = $el;
            } elseif (! empty($el['elements'])) {
                $walk($el['elements']);
            }
        }
    };
    $walk($zone['elements']);

    return $out;
}

it('composes the service body into ordered native zones (containers, not sections)', function () {
    $doc = composedService();

    $classes = array_map(fn ($z) => $z['settings']['_css_classes'], $doc);
    expect($classes)->toBe([
        'lp-zone lp-zone--hero',
        'lp-zone lp-zone--explainer',
        'lp-zone lp-zone--features',
        'lp-zone lp-zone--proof',
        'lp-zone lp-zone--faq',
        'lp-zone lp-zone--cta',
    ]);

    foreach ($doc as $zone) {
        expect($zone['elType'])->toBe('container');
    }
});

it('maps each slot to its verified native widget', function () {
    $by = zonesByClass(composedService());

    $hero = collect(zoneWidgets($by['lp-zone lp-zone--hero']));
    expect($hero->pluck('widgetType')->all())->toBe(['heading', 'heading', 'image'])
        ->and($hero[0]['settings'])->toMatchArray(['title' => 'No hot water?', 'header_size' => 'h1'])
        ->and($hero[1]['settings']['header_size'] ?? 'h2')->toBe('h2')   // hero_solution → h2 heading
        ->and($hero[2]['settings']['image']['url'])->toBe('https://r2.example/hero.jpg');

    $features = collect(zoneWidgets($by['lp-zone lp-zone--features']));
    expect($features->pluck('widgetType')->all())->toBe(['icon-list', 'icon-list'])
        ->and($features[0]['settings']['icon_list'][0]['text'])->toBe('<strong>Endless</strong> hot water')
        ->and($features[0]['settings']['icon_list'][0]['_id'])->toMatch('/^[0-9a-f]{7}$/');

    $faq = zoneWidgets($by['lp-zone lp-zone--faq']);
    expect($faq[0]['widgetType'])->toBe('nested-accordion');

    $cta = zoneWidgets($by['lp-zone lp-zone--cta']);
    expect($cta[0]['widgetType'])->toBe('button')
        ->and($cta[0]['settings']['text'])->toBe('Call Now (555) 123-4567')
        ->and($cta[0]['settings']['link']['url'])->toBe('tel:+15551234567');
});

it('keeps prose as text-editor and skips not-yet-native slots (stat/testimonial/nap → Tier-2)', function () {
    $by = zonesByClass(composedService());

    expect(collect(zoneWidgets($by['lp-zone lp-zone--explainer']))->pluck('widgetType')->all())
        ->toBe(['text-editor', 'text-editor']);

    // proof zone: only why_us prose (stat + testimonial skipped, not guessed).
    expect(collect(zoneWidgets($by['lp-zone lp-zone--proof']))->pluck('widgetType')->all())
        ->toBe(['text-editor']);

    // cta zone: just the button — contact_block (nap) skipped, no url to bind.
    expect(collect(zoneWidgets($by['lp-zone lp-zone--cta']))->pluck('widgetType')->all())
        ->toBe(['button']);
});

it('emits no shortcode or dynamic-tag widgets — every widget is native + content baked', function () {
    $doc = composedService();

    $walk = function (array $els) use (&$walk): void {
        foreach ($els as $el) {
            if (($el['elType'] ?? null) === 'widget') {
                expect($el['widgetType'])->not->toBe('shortcode');
                expect($el['settings'] ?? [])->not->toHaveKey('__dynamic__');
            }
            if (! empty($el['elements'])) {
                $walk($el['elements']);
            }
        }
    };
    $walk($doc);

    expect(json_encode($doc))->toBeString();
});

it('omits a zone whose slots all resolve empty', function () {
    // Only a faq value → only the faq zone is emitted.
    $doc = (new NativeComposer)->compose(serviceKitSchema(), [
        'faq' => [['question' => 'Q?', 'answer' => 'A.']],
    ]);

    expect(array_map(fn ($z) => $z['settings']['_css_classes'], $doc))->toBe(['lp-zone lp-zone--faq']);
});
