<?php

use App\Enums\CandidateScope;
use App\Enums\ShelfLife;

it('derives shelf-life from the timeliness decay signal', function () {
    expect(ShelfLife::fromTimeliness(0.9))->toBe(ShelfLife::Topical)
        ->and(ShelfLife::fromTimeliness(0.5))->toBe(ShelfLife::Topical)  // >= threshold
        ->and(ShelfLife::fromTimeliness(0.49))->toBe(ShelfLife::Evergreen)
        ->and(ShelfLife::fromTimeliness(0.0))->toBe(ShelfLife::Evergreen);
});

it('derives scope from the local-relevance signal', function () {
    expect(CandidateScope::fromLocal(true))->toBe(CandidateScope::Local)
        ->and(CandidateScope::fromLocal(false))->toBe(CandidateScope::General);
});

it('the two axes are independent — every shelf-life × scope combination is expressible', function () {
    // The single CandidateClassification enum could not represent "local AND topical" — these can.
    expect([ShelfLife::fromTimeliness(0.9), CandidateScope::fromLocal(true)])
        ->toBe([ShelfLife::Topical, CandidateScope::Local]);
    expect([ShelfLife::fromTimeliness(0.1), CandidateScope::fromLocal(true)])
        ->toBe([ShelfLife::Evergreen, CandidateScope::Local]);
});
