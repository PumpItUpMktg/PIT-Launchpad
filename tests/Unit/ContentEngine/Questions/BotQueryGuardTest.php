<?php

use App\ContentEngine\Questions\BotQueryGuard;
use App\ContentEngine\Questions\QuestionStat;

function qstat(array $o = []): QuestionStat
{
    return new QuestionStat(
        query: $o['query'] ?? 'how long do sump pumps last',
        impressions: $o['impressions'] ?? 120,
        clicks: $o['clicks'] ?? 3,
        position: array_key_exists('position', $o) ? $o['position'] : 12.0,
        bestPosition: $o['best'] ?? 8.0,
        daysSeen: $o['days'] ?? 40,
        firstSeen: $o['first'] ?? '2026-08-01',
        lastSeen: $o['last'] ?? '2026-10-30',
        peakDaily: $o['peak'] ?? 9,
    );
}

$asOf = new DateTimeImmutable('2026-11-01');

it('passes a real question: clicked occasionally, ramped over weeks', function () use ($asOf) {
    expect((new BotQueryGuard)->reason(qstat(), $asOf))->toBeNull();
});

it('drops zero-click traffic in the top 20, and any-position traffic at ten times the threshold', function () use ($asOf) {
    $guard = new BotQueryGuard(zeroClickImpressions: 50);

    expect($guard->reason(qstat(['impressions' => 80, 'clicks' => 0, 'position' => 6.0]), $asOf))->toBe('zero_click')
        ->and($guard->reason(qstat(['impressions' => 80, 'clicks' => 0, 'position' => 45.0]), $asOf))->toBeNull() // deep, unclicked, plausible
        ->and($guard->reason(qstat(['impressions' => 600, 'clicks' => 0, 'position' => 45.0]), $asOf))->toBe('zero_click');
});

it('drops a query that appeared overnight at volume', function () use ($asOf) {
    $guard = new BotQueryGuard(onsetDays: 14, onsetDailyImpressions: 100);

    expect($guard->reason(qstat(['first' => '2026-10-27', 'peak' => 1200, 'clicks' => 1, 'impressions' => 3000, 'position' => 3.0]), $asOf))->toBe('sudden_onset')
        ->and($guard->reason(qstat(['first' => '2026-08-01', 'peak' => 1200, 'clicks' => 40, 'impressions' => 3000, 'position' => 3.0]), $asOf))->toBeNull(); // old, clicked
});

it('drops an implausible click-through at a top slot', function () use ($asOf) {
    $guard = new BotQueryGuard(ctrPosition: 10, ctrImpressions: 200, ctrFloor: 0.001);

    expect($guard->reason(qstat(['impressions' => 2500, 'clicks' => 1, 'position' => 2.0]), $asOf))->toBe('implausible_ctr')
        ->and($guard->reason(qstat(['impressions' => 2500, 'clicks' => 30, 'position' => 2.0]), $asOf))->toBeNull()
        ->and($guard->reason(qstat(['impressions' => 2500, 'clicks' => 1, 'position' => 35.0]), $asOf))->toBeNull(); // deep: CTR near zero is normal
});
