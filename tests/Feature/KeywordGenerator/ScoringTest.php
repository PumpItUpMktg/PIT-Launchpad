<?php

use App\Enums\IntentLevel;
use App\KeywordGenerator\Scoring\OpportunityScorer;
use App\KeywordGenerator\Scoring\ScoringWeights;

test('beatability multiplies the opportunity', function () {
    $scorer = new OpportunityScorer;

    $strong = $scorer->score(5000, 30, IntentLevel::Transactional, 0.8, 1.0);
    $weak = $scorer->score(5000, 30, IntentLevel::Transactional, 0.8, 0.1);

    expect($weak->opportunity)->toBeLessThan($strong->opportunity)
        ->and($weak->opportunity)->toEqualWithDelta($strong->opportunity * 0.1, 0.0001);
});

test('business value carries the heaviest weight', function () {
    $scorer = new OpportunityScorer;

    $highValue = $scorer->score(100, 30, IntentLevel::Commercial, 1.0, 1.0);   // low demand, max value
    $highDemand = $scorer->score(50000, 30, IntentLevel::Commercial, 0.0, 1.0); // max demand, no value

    expect($highValue->opportunity)->toBeGreaterThan($highDemand->opportunity);
});

test('the vanity guard down-weights high-volume no-revenue informational keywords', function () {
    $scorer = new OpportunityScorer;

    $result = $scorer->score(40000, 20, IntentLevel::Informational, 0.1, 0.9);

    expect($result->vanityPenalized)->toBeTrue();

    $nonVanity = $scorer->score(40000, 20, IntentLevel::Informational, 0.5, 0.9);
    expect($nonVanity->vanityPenalized)->toBeFalse()
        ->and($result->opportunity)->toBeLessThan($nonVanity->opportunity);
});

test('weights are tunable per tenant', function () {
    $scorer = new OpportunityScorer(new ScoringWeights(demand: 1.0, intent: 0.0, value: 0.0));

    $result = $scorer->score(10000, 0, IntentLevel::Informational, 1.0, 1.0);

    // demand of a 10k-volume keyword is ~1, weighted only by demand.
    expect($result->opportunity)->toEqualWithDelta($result->demand, 0.0001);
});

test('quick-win prioritises lower difficulty at equal opportunity', function () {
    $scorer = new OpportunityScorer;

    $easy = $scorer->score(5000, 10, IntentLevel::Transactional, 0.6, 0.8);
    $hard = $scorer->score(5000, 90, IntentLevel::Transactional, 0.6, 0.8);

    expect($easy->opportunity)->toEqualWithDelta($hard->opportunity, 0.0001)
        ->and($easy->quickWin)->toBeGreaterThan($hard->quickWin);
});
