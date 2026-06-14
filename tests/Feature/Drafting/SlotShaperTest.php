<?php

use App\ContentEngine\Drafting\SlotShaper;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;

function serviceSlots(): array
{
    (new WireframeKitSeeder)->run();

    /** @var WireframeKit $kit */
    $kit = WireframeKit::where('page_type', 'service')->firstOrFail();

    return $kit->schema()->slots;
}

it('shapes a faq repeater from raw ||-delimited blocks into {question, answer} items', function () {
    $slots = serviceSlots();
    $shaped = (new SlotShaper)->shape($slots, [
        'faq' => [
            'How long does install take? || Most installs are same-day.',
            'Will it lower my bills? || Tankless heats on demand.',
        ],
    ]);

    expect($shaped['faq'])->toBe([
        ['question' => 'How long does install take?', 'answer' => 'Most installs are same-day.'],
        ['question' => 'Will it lower my bills?', 'answer' => 'Tankless heats on demand.'],
    ]);
});

it('keeps a single text slot a scalar and a list repeater plain strings', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'hero_problem' => 'No hot water?',
        'service_features' => ['Endless hot water', 'Lower bills', 'Compact'],
    ]);

    expect($shaped['hero_problem'])->toBe('No hot water?')
        ->and($shaped['service_features'])->toBe(['Endless hot water', 'Lower bills', 'Compact']);
});

it('wraps a lone repeater item into a list so cardinality is judged correctly', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'service_features' => 'Just one feature', // parser gave a scalar (one block)
    ]);

    expect($shaped['service_features'])->toBe(['Just one feature']);
});

it('converts Markdown in text slots to HTML (no literal **bold** or – bullets)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'problem_explainer' => "A failing heater is **expensive**.\n\n- lukewarm showers\n- rising bills",
        'hero_solution' => 'Same-day **guaranteed** repair.',
        'hero_problem' => 'No **hot** water?', // heading stays plain
    ]);

    expect($shaped['problem_explainer'])->toContain('<strong>expensive</strong>')
        ->and($shaped['problem_explainer'])->toContain('<li>lukewarm showers</li>')
        ->and($shaped['problem_explainer'])->not->toContain('**')
        ->and($shaped['hero_solution'])->toContain('<strong>guaranteed</strong>')
        ->and($shaped['hero_solution'])->not->toContain('<p>')      // inline: no block wrap
        ->and($shaped['hero_problem'])->toBe('No **hot** water?');  // heading untouched
});

it('converts Markdown in list repeater items to HTML (no literal **bold**)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'process_steps' => ['**Step 1 — Inspect** the system', '**Step 2 — Quote** the work'],
    ]);

    expect($shaped['process_steps'][0])->toContain('<strong>Step 1 — Inspect</strong>')
        ->and($shaped['process_steps'][0])->not->toContain('**')
        ->and($shaped['process_steps'][0])->not->toContain('<p>'); // inline: no block wrap
});

it('converts Markdown in a faq answer to HTML', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => ['Will it save money? || Yes — up to **40%** on bills.'],
    ]);

    expect($shaped['faq'][0]['answer'])->toContain('<strong>40%</strong>')
        ->and($shaped['faq'][0]['answer'])->not->toContain('**')
        ->and($shaped['faq'][0]['question'])->toBe('Will it save money?'); // question stays plain
});

it('splits a single bulleted block into separate list items', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'service_features' => "– Endless hot water\n– Lower bills\n– Compact footprint",
    ]);

    expect($shaped['service_features'])->toBe(['Endless hot water', 'Lower bills', 'Compact footprint']);
});

it('splits a labeled faq block into {question, answer} (the recurring q/a bug)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => [
            "Question: How long does install take?\nAnswer: Most installs are same-day.",
            "Will it lower my bills?\nTankless heats on demand.",
        ],
    ]);

    expect($shaped['faq'])->toBe([
        ['question' => 'How long does install take?', 'answer' => 'Most installs are same-day.'],
        ['question' => 'Will it lower my bills?', 'answer' => 'Tankless heats on demand.'],
    ]);
});

it('drops off-schema keys (the slot key is the render contract)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'hero_problem' => 'kept',
        'totally_made_up' => 'dropped',
    ]);

    expect($shaped)->toHaveKey('hero_problem')
        ->and($shaped)->not->toHaveKey('totally_made_up');
});
