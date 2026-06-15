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

it('caps body-slot headings at H3 (the section already supplies the H2)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        // ## → h2 (Markdown), a raw <h1>, and an existing <h3> that must stay put.
        'problem_explainer' => "## What We Do\n\nWe fix it.\n\n<h1>Big claim</h1>\n\n### Fine print\n\nDetails.",
    ]);

    expect($shaped['problem_explainer'])
        ->toContain('<h3>What We Do</h3>')   // ## (h2) demoted
        ->toContain('<h3>Big claim</h3>')    // raw <h1> demoted
        ->toContain('<h3>Fine print</h3>')   // ### (h3) untouched
        ->not->toContain('<h1')
        ->and($shaped['problem_explainer'])->not->toContain('<h2');
});

it('preserves heading attributes while demoting the level', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'problem_explainer' => '<h2 class="lead" id="x">Heading</h2>',
    ]);

    expect($shaped['problem_explainer'])->toContain('<h3 class="lead" id="x">Heading</h3>');
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

it('parses the labeled-|| faq shape — the model echoed the field-name hint (post-118 bug)', function () {
    // Each item: the model emitted the `[fields: question || answer]` hint as two
    // labeled lines. The old delimiter split produced {question:"question",
    // answer:"<realQ>\nanswer"} and DROPPED the real answer.
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => [
            "question || What is a sump pump?\nanswer || A pump that removes water.",
            "question || How much does it cost?\nanswer || It varies by setup.",
        ],
    ]);

    expect($shaped['faq'])->toBe([
        ['question' => 'What is a sump pump?', 'answer' => 'A pump that removes water.'],
        ['question' => 'How much does it cost?', 'answer' => 'It varies by setup.'],
    ]);
});

it('never lets the literal label "question" become the faq title (post-118 regression guard)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => ["question || Do you offer financing?\nanswer || Yes, **0% APR**."],
    ]);

    expect($shaped['faq'][0]['question'])->toBe('Do you offer financing?')   // not "question"
        ->and($shaped['faq'][0]['answer'])->toContain('<strong>0% APR</strong>') // real answer kept + rendered
        ->and($shaped['faq'][0]['answer'])->not->toContain('answer');         // label fragment dropped
});

it('drops off-schema keys (the slot key is the render contract)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'hero_problem' => 'kept',
        'totally_made_up' => 'dropped',
    ]);

    expect($shaped)->toHaveKey('hero_problem')
        ->and($shaped)->not->toHaveKey('totally_made_up');
});

it('splits a BARE-LABEL faq block (question/answer on their own lines) — the native-cutover bug', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => [
            // The exact shape that produced {question:"question", answer:"...?\nanswer\n..."} live.
            "question\nHow long does install take?\nanswer\nMost installs are same-day.",
            // Single-letter bare labels.
            "Q\nWill it lower my bills?\nA\nTankless heats on demand.",
        ],
    ]);

    expect($shaped['faq'])->toBe([
        ['question' => 'How long does install take?', 'answer' => 'Most installs are same-day.'],
        ['question' => 'Will it lower my bills?', 'answer' => 'Tankless heats on demand.'],
    ]);
});

it('never collapses the label word "question" into the title (regression guard)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => ["question\nDo you offer financing?\nanswer\nYes, **0% APR**."],
    ]);

    expect($shaped['faq'][0]['question'])->toBe('Do you offer financing?')   // not "question"
        ->and($shaped['faq'][0]['answer'])->toContain('<strong>0% APR</strong>')
        ->and($shaped['faq'][0]['answer'])->not->toContain('answer');         // label line dropped
});

it('keeps a multi-line answer after a bare answer label', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => ["question\nWhat is included?\nanswer\nThe unit.\nThe install.\nHaul-away."],
    ]);

    expect($shaped['faq'][0]['question'])->toBe('What is included?')
        ->and($shaped['faq'][0]['answer'])->toContain('The unit.')
        ->and($shaped['faq'][0]['answer'])->toContain('Haul-away.');
});

it('strips wrapping markdown emphasis from faq questions (plain title + clean schema name)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'faq' => [
            '**How long does install take?** || Most installs are same-day.',     // ** via delimiter path
            "question\n__Do you offer financing?__\nanswer\nYes, **0% APR**.",     // __ via bare-label path
        ],
    ]);

    expect($shaped['faq'][0]['question'])->toBe('How long does install take?')   // no **
        ->and($shaped['faq'][1]['question'])->toBe('Do you offer financing?')     // no __
        ->and($shaped['faq'][1]['answer'])->toContain('<strong>0% APR</strong>'); // answer emphasis still rendered
});
