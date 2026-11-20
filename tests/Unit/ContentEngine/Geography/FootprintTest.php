<?php

use App\ContentEngine\Geography\Footprint;
use App\ContentEngine\Geography\UsStates;
use App\Enums\PlaceVerdict;

it('classifies a place by state: footprint, region ring, outside, and never guesses without a place or a footprint', function () {
    $fp = new Footprint(states: ['NJ', 'PA'], ring: ['DE', 'MD', 'NY', 'OH', 'WV'], towns: ['Montclair NJ', 'Trooper PA']);

    expect($fp->classify('PA'))->toBe(PlaceVerdict::Footprint)
        ->and($fp->classify('new jersey'))->toBe(PlaceVerdict::Footprint)
        ->and($fp->classify('NY'))->toBe(PlaceVerdict::Region)
        ->and($fp->classify('Maryland'))->toBe(PlaceVerdict::Region)
        ->and($fp->classify('NE'))->toBe(PlaceVerdict::Outside)
        ->and($fp->classify('Ontario'))->toBe(PlaceVerdict::Unknown)   // not a US state → no verdict
        ->and($fp->classify(null))->toBe(PlaceVerdict::Unknown)
        ->and($fp->classify(''))->toBe(PlaceVerdict::Unknown);

    $unknown = new Footprint([], [], []);
    expect($unknown->isKnown())->toBeFalse()
        ->and($unknown->classify('NE'))->toBe(PlaceVerdict::Unknown)
        ->and($unknown->promptLine())->toBeNull();
});

it('writes the service-area line with states, a capped town list and the ring named as the wider region', function () {
    $towns = array_map(fn (int $i): string => "Town{$i} NJ", range(1, Footprint::PROMPT_TOWN_CAP + 5));
    $fp = new Footprint(['NJ', 'PA'], ['DE', 'NY'], $towns);
    $line = (string) $fp->promptLine();

    expect($line)->toContain('Service area (states): New Jersey (NJ), Pennsylvania (PA).')
        ->toContain('Town1 NJ; Town2 NJ')
        ->toContain('Town'.Footprint::PROMPT_TOWN_CAP.' NJ; …')
        ->not->toContain('Town'.(Footprint::PROMPT_TOWN_CAP + 1).' NJ')
        ->toContain('Neighbouring states (DE, NY) are the wider region, not the service area.');
});

it('resolves state codes and names, and the adjacency table is symmetric', function () {
    expect(UsStates::code('pa'))->toBe('PA')
        ->and(UsStates::code('Pennsylvania'))->toBe('PA')
        ->and(UsStates::code('Repair'))->toBeNull()
        ->and(UsStates::code(null))->toBeNull()
        ->and(UsStates::name('NJ'))->toBe('New Jersey');

    foreach (UsStates::NEIGHBORS as $state => $neighbors) {
        foreach ($neighbors as $n) {
            expect(in_array($state, UsStates::NEIGHBORS[$n], true))->toBeTrue("{$n} should list {$state}");
        }
    }
});
