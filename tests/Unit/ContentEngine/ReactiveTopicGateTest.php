<?php

use App\ContentEngine\ReactiveTopicGate;
use Tests\Support\News;

function spgGate(): ReactiveTopicGate
{
    return new ReactiveTopicGate(
        allow: ['flood', 'flooding', 'basement water', 'sump pump', 'drainage', 'foundation crack', 'radon', 'groundwater'],
        denyContext: ['rate hike', 'grant', 'budget', 'utility bill', 'tax'],
    );
}

it('passes a buyer-intent story that hits an allow topic', function () {
    expect(spgGate()->rejection(News::item('Flash flood protection tips for your basement', summary: 'Keep groundwater out.'), ['NJ', 'PA']))
        ->toBeNull();
});

it('drops a municipal-utility-finance story with no on-topic signal', function () {
    // The Aug-4 leak: sewer grants, rate hikes, utility bills — no allow-topic hit.
    expect(spgGate()->rejection(News::item('County approves $22M in sewer grants', summary: 'Ratepayers to see utility bill changes.'), ['NJ', 'PA']))
        ->toBe('off_topic')
        ->and(spgGate()->rejection(News::item('New Brunswick water/sewer rate hike approved', summary: 'The council passed the budget.'), ['NJ', 'PA']))
        ->toBe('off_topic');
});

it('vetoes a finance HEADLINE even when the body mentions an on-topic word', function () {
    expect(spgGate()->rejection(News::item('Township approves stormwater fee rate hike', summary: 'Officials cited basement flooding downtown.'), ['NJ', 'PA']))
        ->toBe('off_topic'); // "rate hike" in the title wins
});

it('drops an out-of-footprint story anchored to another state', function () {
    expect(spgGate()->rejection(News::item('Basement flooding worsens across the region', summary: 'Homeowners in Lansing, Michigan are pumping out water.'), ['NJ', 'PA']))
        ->toBe('out_of_footprint');
});

it('keeps an on-topic story that also names an in-footprint state', function () {
    expect(spgGate()->rejection(News::item('Sump pump demand rises after storms', summary: 'Flooding hit Trenton, New Jersey this week.'), ['NJ', 'PA']))
        ->toBeNull();
});

it('does not false-drop an in-state TOWN that shares a state name (Washington, NJ)', function () {
    // "Washington" the NJ town must not trip the out-of-footprint state matcher.
    expect(spgGate()->rejection(News::item('Sump pump installs surge in Washington after flooding', summary: 'Basement water is the culprit.'), ['NJ', 'PA']))
        ->toBeNull();
});

it('never guesses geography when the footprint is unknown', function () {
    expect(spgGate()->rejection(News::item('Basement flooding in Lansing, Michigan', summary: 'Groundwater rising.'), []))
        ->toBeNull(); // topical passes; no footprint → no geo drop
});

it('denies a watershed-governance headline that slips past the allowlist via a drainage word (§8.6)', function () {
    // "drainage" clears the allowlist, but a watershed-management HEADLINE is municipal governance, not a
    // homeowner topic — the §8.6 deny-term catches it.
    $gate = new ReactiveTopicGate(allow: ['drainage', 'flood', 'sump pump'], denyContext: ['grant', 'watershed']);
    expect($gate->rejection(News::item('County adopts new watershed drainage management plan', summary: ''), ['NJ', 'PA']))
        ->toBe('off_topic');
});
