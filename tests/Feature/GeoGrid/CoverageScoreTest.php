<?php

use App\GeoGrid\CoverageScore;

it('gives rank-decay credit: #1 full, fading to ~0 at depth, not-found zero', function () {
    expect(CoverageScore::credit(1, 20))->toBe(1.0)
        ->and(CoverageScore::credit(3, 20))->toBe(0.9)
        ->and(CoverageScore::credit(10, 20))->toBe(0.55)
        ->and(CoverageScore::credit(20, 20))->toBe(0.05)
        ->and(CoverageScore::credit(null, 20))->toBe(0.0);
});

it('population-weights the visibility score across towns', function () {
    // #1 in a 90k town, invisible in a 10k town → 90/100 of the weighted credit.
    $score = app(CoverageScore::class)->compute([
        ['rank' => 1, 'population' => 90000],
        ['rank' => null, 'population' => 10000],
    ], 20);

    expect($score)->toBe(90.0);
});

it('counts towns with unknown population at weight 1 rather than dropping them', function () {
    // Both weight 1: (#1 credit 1.0 + not-found 0) / 2 = 50.
    expect(app(CoverageScore::class)->compute([
        ['rank' => 1, 'population' => 0],
        ['rank' => null, 'population' => 0],
    ], 20))->toBe(50.0)
        ->and(app(CoverageScore::class)->compute([], 20))->toBeNull();
});
