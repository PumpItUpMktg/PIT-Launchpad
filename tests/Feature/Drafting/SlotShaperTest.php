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

it('drops off-schema keys (the slot key is the render contract)', function () {
    $shaped = (new SlotShaper)->shape(serviceSlots(), [
        'hero_problem' => 'kept',
        'totally_made_up' => 'dropped',
    ]);

    expect($shaped)->toHaveKey('hero_problem')
        ->and($shaped)->not->toHaveKey('totally_made_up');
});
