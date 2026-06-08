<?php

use App\Enums\MarketTier;
use App\Enums\SampleType;
use App\Enums\SamplingTier;
use App\Enums\TargetLifecycle;
use App\KeywordGenerator\Cadence\CadenceScheduler;
use App\KeywordGenerator\Cadence\SamplingTask;
use App\KeywordGenerator\Cadence\Tiering;

test('tiering blends business value, market priority and lifecycle', function () {
    $tiering = new Tiering;

    expect($tiering->tierFor(0.8, MarketTier::Priority))->toBe(SamplingTier::A)
        ->and($tiering->tierFor(0.4, null))->toBe(SamplingTier::B)
        ->and($tiering->tierFor(0.1, MarketTier::Coverage))->toBe(SamplingTier::C)
        ->and($tiering->tierFor(0.8, null, TargetLifecycle::Parked))->toBe(SamplingTier::C)
        ->and($tiering->tierFor(0.4, null, TargetLifecycle::Active, volatilityBump: true))->toBe(SamplingTier::A);
});

test('cadence days follow the tier', function () {
    $tiering = new Tiering;

    expect($tiering->cadenceDays(SamplingTier::A, SampleType::Positions))->toBe(7)
        ->and($tiering->cadenceDays(SamplingTier::C, SampleType::Serp))->toBe(180);
});

test('the budget ceiling degrades coverage and low tiers first and keeps forced triggers', function () {
    $tasks = [
        new SamplingTask('priorityA', SampleType::Positions, SamplingTier::A, 7, 5.0, isCoverageMarket: false),
        new SamplingTask('standardB', SampleType::Positions, SamplingTier::B, 30, 3.0, isCoverageMarket: false),
        new SamplingTask('coverageC', SampleType::Positions, SamplingTier::C, 90, 3.0, isCoverageMarket: true, marketId: 'm-cov'),
        new SamplingTask('coverageC2', SampleType::Serp, SamplingTier::C, 180, 3.0, isCoverageMarket: true, marketId: 'm-cov'),
        new SamplingTask('triggered', SampleType::Positions, SamplingTier::C, 1, 4.0, forced: true),
    ];

    // Total = 18; ceiling 12 forces ~6 units of drops.
    $plan = (new CadenceScheduler)->fit($tasks, budgetCeiling: 12.0);

    expect($plan->includes('triggered'))->toBeTrue()      // forced trigger survives
        ->and($plan->includes('priorityA'))->toBeTrue()   // top tier survives
        ->and($plan->dropped('coverageC'))->toBeTrue()    // coverage C dropped first
        ->and($plan->dropped('coverageC2'))->toBeTrue()
        ->and($plan->totalCost)->toBeLessThanOrEqual(12.0);
});
