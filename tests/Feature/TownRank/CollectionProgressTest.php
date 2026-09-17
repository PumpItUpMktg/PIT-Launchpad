<?php

use App\TownRank\CollectionProgress;

beforeEach(fn () => config(['services.dataforseo.read_rate_limit_per_min' => 600]));

it('states what is left and the floor on how long it takes, from the read ceiling', function () {
    // 600 reads a minute → 10 a second. 722 towns, 550 collected, 172 left ≈ 18s.
    expect(CollectionProgress::for(550, 722))->toMatchArray([
        'collected' => 550, 'points' => 722, 'remaining' => 172, 'eta_seconds' => 18, 'eta' => 'under a minute',
    ]);

    // A whole untouched sweep of one mode: 722 ÷ 10 per second ≈ 73s.
    expect(CollectionProgress::for(0, 722))->toMatchArray(['remaining' => 722, 'eta_seconds' => 73, 'eta' => 'about 1 minute']);

    // Nothing left: no estimate at all, rather than "0 seconds".
    expect(CollectionProgress::for(722, 722))->toMatchArray(['remaining' => 0, 'eta_seconds' => null, 'eta' => null]);
});

it('never quotes a time faster than the rate limit allows, and follows the configured rate', function () {
    config(['services.dataforseo.read_rate_limit_per_min' => 60]);

    // A tenth of the rate is ten times the wait: 600 towns at 60/min = 10 minutes.
    expect(CollectionProgress::for(0, 600)['eta'])->toBe('about 10 minutes')
        ->and(CollectionProgress::for(0, 600)['eta_seconds'])->toBe(600);
});

it('reads coarsely — minutes, then hours', function () {
    expect(CollectionProgress::label(30))->toBe('under a minute')
        ->and(CollectionProgress::label(60))->toBe('about 1 minute')
        ->and(CollectionProgress::label(240))->toBe('about 4 minutes')
        ->and(CollectionProgress::label(3600))->toBe('about 1h')
        ->and(CollectionProgress::label(4800))->toBe('about 1h 20m');
});
