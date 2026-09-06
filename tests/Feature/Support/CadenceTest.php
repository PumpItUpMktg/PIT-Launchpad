<?php

use App\Enums\FreshnessState;
use App\Support\Cadence;
use App\Support\FreshnessStamp;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

// Set the cadence config explicitly so the assertions prove the SOURCING logic, independent of any
// ambient env override (the deployment may pin KEYWORD_TRACKING_CADENCE_DAYS).
beforeEach(function () {
    config()->set('content_engine.pipeline.tracking_cadence_days', 7);
    config()->set('launchpad.cadence', ['gsc' => 1, 'index' => 1, 'geo' => 7]);
});

it('returns the per-dataset interval in seconds, matching the scheduled cadence', function () {
    expect(Cadence::intervalSeconds('gsc'))->toBe(86400)      // daily
        ->and(Cadence::intervalSeconds('index'))->toBe(86400) // daily
        ->and(Cadence::intervalSeconds('geo'))->toBe(604800)  // weekly
        ->and(Cadence::intervalSeconds('serp'))->toBe(604800); // weekly (the §5 tracking gate)
});

it('sources serp from the §5 tracking gate so the two never drift', function () {
    config()->set('content_engine.pipeline.tracking_cadence_days', 14);

    expect(Cadence::intervalSeconds('serp'))->toBe(14 * 86400);
});

it('returns null for an unknown dataset (an un-configured panel stays quiet)', function () {
    expect(Cadence::intervalSeconds('nope'))->toBeNull();
});

it('feeds a real interval into the freshness stamp — weekly SERP is fresh at 5 days, stale for daily GSC', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');
    $fiveDaysAgo = Carbon::now()->subDays(5);

    $serp = FreshnessStamp::for($fiveDaysAgo, Cadence::intervalSeconds('serp'), null, 'positions');
    $gsc = FreshnessStamp::for($fiveDaysAgo, Cadence::intervalSeconds('gsc'), null, 'search data');

    expect($serp->state)->toBe(FreshnessState::Fresh)  // 5d < 7d weekly interval
        ->and($gsc->state)->toBe(FreshnessState::Stale); // 5d > 2× daily interval
});
