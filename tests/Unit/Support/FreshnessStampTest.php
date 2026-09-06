<?php

use App\Enums\FreshnessState;
use App\Support\FreshnessStamp;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('reads "as of {date}" with the exact timestamp on hover, and the state from the interval', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');
    $stamp = FreshnessStamp::for(Carbon::parse('2026-09-05 06:00:00'), 86400, null, 'positions');

    expect($stamp->line())->toBe('Positions as of 5 Sep')
        ->and($stamp->exact())->toContain('Sep 5, 2026')
        ->and($stamp->state)->toBe(FreshnessState::Late)   // 30h old on a daily interval → 1–2 intervals
        ->and($stamp->severity)->toBe('late');
});

it('reads an honest "never checked" line when no check has run', function () {
    $stamp = FreshnessStamp::for(null, 86400, null, 'positions');

    expect($stamp->line())->toBe('Positions — never checked')
        ->and($stamp->exact())->toBeNull()
        ->and($stamp->state)->toBe(FreshnessState::NeverChecked)
        ->and($stamp->severity)->toBe('never_checked'); // no tracking start → quiet
});

it('keeps a never-checked panel QUIET while within one interval of tracking start', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');
    $stamp = FreshnessStamp::for(null, 86400, Carbon::now()->subHours(12), 'positions');

    // brand new (tracking started 12h ago, interval 24h) — still quiet, text honest.
    expect($stamp->state)->toBe(FreshnessState::NeverChecked)
        ->and($stamp->severity)->toBe('never_checked')
        ->and($stamp->line())->toBe('Positions — never checked');
});

it('escalates a never-checked panel once overdue relative to tracking start (text stays honest)', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');
    $day = 86400;

    // 1–2 intervals past tracking start → late; beyond → stale. The LINE still says "never checked".
    $late = FreshnessStamp::for(null, $day, Carbon::now()->subSeconds((int) (1.5 * $day)), 'positions');
    $stale = FreshnessStamp::for(null, $day, Carbon::now()->subSeconds(3 * $day), 'positions');

    expect($late->severity)->toBe('late')
        ->and($late->state)->toBe(FreshnessState::NeverChecked)
        ->and($late->line())->toBe('Positions — never checked')
        ->and($stale->severity)->toBe('stale')
        ->and($stale->state)->toBe(FreshnessState::NeverChecked);
});

it('stays Fresh (quiet) for an un-configured interval or a future timestamp — inheriting fromCheck edges', function () {
    Carbon::setTestNow('2026-09-06 12:00:00');

    expect(FreshnessStamp::for(Carbon::now()->subYears(3), null)->state)->toBe(FreshnessState::Fresh)   // no interval
        ->and(FreshnessStamp::for(Carbon::now()->addHours(6), 86400)->state)->toBe(FreshnessState::Fresh); // clock skew
});
