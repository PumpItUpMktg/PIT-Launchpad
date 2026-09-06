<?php

use App\Enums\RankingState;

it('has the four distinct ranking states with the expected backing values', function () {
    expect(array_map(fn (RankingState $s): string => $s->value, RankingState::cases()))
        ->toBe(['ranked', 'tracked_not_ranking', 'checking', 'not_tracked']);
});

it('distinguishes a coverage gap (not_tracked) from an active check', function () {
    // The load-bearing distinction: "add a keyword" vs "improve a page".
    expect(RankingState::NotTracked->isTracked())->toBeFalse()
        ->and(RankingState::TrackedNotRanking->isTracked())->toBeTrue()
        ->and(RankingState::Ranked->isTracked())->toBeTrue()
        ->and(RankingState::Checking->isTracked())->toBeTrue();
});

it('every state has a non-empty label', function () {
    foreach (RankingState::cases() as $state) {
        expect($state->label())->toBeString()->not->toBe('');
    }
});
