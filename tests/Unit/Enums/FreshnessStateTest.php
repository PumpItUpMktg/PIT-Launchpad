<?php

use App\Enums\FreshnessState;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('returns Fresh for an un-configured (null or non-positive) interval, whatever the timestamp', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');

    // The un-configured case (most panels until PR 3): quiet, never alarms, never divides by zero.
    expect(FreshnessState::fromCheck(null, null))->toBe(FreshnessState::Fresh)
        ->and(FreshnessState::fromCheck(Carbon::now()->subYears(5), null))->toBe(FreshnessState::Fresh)
        ->and(FreshnessState::fromCheck(Carbon::now()->subYears(5), 0))->toBe(FreshnessState::Fresh)
        ->and(FreshnessState::fromCheck(Carbon::now()->subYears(5), -100))->toBe(FreshnessState::Fresh);
});

it('returns NeverChecked when a cadence is set but no check has ever run', function () {
    expect(FreshnessState::fromCheck(null, 86400))->toBe(FreshnessState::NeverChecked);
});

it('treats a future timestamp (clock skew) as Fresh, never Stale', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');

    // Laravel Cloud ↔ Postgres skew: a lastChecked in the future must not compute as stale.
    expect(FreshnessState::fromCheck(Carbon::now()->addHours(6), 86400))->toBe(FreshnessState::Fresh)
        ->and(FreshnessState::fromCheck(Carbon::now()->addYears(1), 86400))->toBe(FreshnessState::Fresh);
});

it('derives fresh / late / stale from the interval, at the exact boundaries', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');
    $day = 86400;

    // <= 1 interval → Fresh (boundary inclusive)
    expect(FreshnessState::fromCheck(Carbon::now(), $day))->toBe(FreshnessState::Fresh)
        ->and(FreshnessState::fromCheck(Carbon::now()->subSeconds($day), $day))->toBe(FreshnessState::Fresh)
        // just past 1 interval → Late
        ->and(FreshnessState::fromCheck(Carbon::now()->subSeconds($day + 1), $day))->toBe(FreshnessState::Late)
        // exactly 2 intervals → Late (boundary inclusive)
        ->and(FreshnessState::fromCheck(Carbon::now()->subSeconds(2 * $day), $day))->toBe(FreshnessState::Late)
        // just past 2 intervals → Stale
        ->and(FreshnessState::fromCheck(Carbon::now()->subSeconds(2 * $day + 1), $day))->toBe(FreshnessState::Stale);
});

it('scales with the interval — a weekly panel and a daily panel escalate at different ages', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');
    $week = 7 * 86400;

    // 5 days old: fresh for a weekly panel, stale for a daily one — same component, no per-surface numbers.
    expect(FreshnessState::fromCheck(Carbon::now()->subDays(5), $week))->toBe(FreshnessState::Fresh)
        ->and(FreshnessState::fromCheck(Carbon::now()->subDays(5), 86400))->toBe(FreshnessState::Stale);
});

it('every state has a label', function () {
    foreach (FreshnessState::cases() as $state) {
        expect($state->label())->toBeString()->not->toBe('');
    }
});
