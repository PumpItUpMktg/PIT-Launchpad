<?php

use App\ContentEngine\Reconcile\LocalTownCoherence;
use App\ContentEngine\Reconcile\LocalTownMatcher;

it('does not tag a common-word town on a bare mention, but does when state-qualified', function () {
    $towns = [
        ['key' => 'deal', 'display' => 'Deal, NJ', 'name' => 'Deal', 'county' => '34025', 'state' => 'nj'],
        ['key' => 'wall', 'display' => 'Wall, NJ', 'name' => 'Wall', 'county' => '34025', 'state' => 'nj'],
        ['key' => 'hoboken', 'display' => 'Hoboken, NJ', 'name' => 'Hoboken', 'county' => '34017', 'state' => 'nj'],
    ];

    // "a good deal" / "the basement wall" must NOT tag Deal or Wall; Hoboken (unambiguous) matches bare.
    $bare = LocalTownMatcher::scan('We got a good deal fixing the basement wall in Hoboken.', $towns);
    expect(collect($bare)->pluck('key')->all())->toBe(['hoboken']);

    // A state-qualified mention of the common-word town counts.
    $qualified = LocalTownMatcher::scan('Flooding hit Deal, NJ over the weekend.', $towns);
    expect(collect($qualified)->pluck('key')->all())->toBe(['deal']);
});

it('lets the longer town name claim its span (no substring double-tag)', function () {
    $towns = [
        ['key' => 'brunswick', 'display' => 'Brunswick, NJ', 'name' => 'Brunswick', 'county' => '34023', 'state' => 'nj'],
        ['key' => 'new brunswick', 'display' => 'New Brunswick, NJ', 'name' => 'New Brunswick', 'county' => '34023', 'state' => 'nj'],
    ];

    $m = LocalTownMatcher::scan('A water main broke in New Brunswick today.', $towns);
    expect(collect($m)->pluck('key')->all())->toBe(['new brunswick']);
});

it('keeps only the dominant county+state cluster, capped', function () {
    $matched = [
        ['key' => 'a', 'display' => 'A', 'county' => '34023', 'state' => 'nj', 'pos' => 5],
        ['key' => 'b', 'display' => 'B', 'county' => '34023', 'state' => 'nj', 'pos' => 10],
        ['key' => 'c', 'display' => 'C', 'county' => '34023', 'state' => 'nj', 'pos' => 20],
        ['key' => 'd', 'display' => 'D', 'county' => '34023', 'state' => 'nj', 'pos' => 30],
        ['key' => 'e', 'display' => 'E', 'county' => '34023', 'state' => 'nj', 'pos' => 40],
        ['key' => 'x', 'display' => 'X', 'county' => '42029', 'state' => 'pa', 'pos' => 2], // PA outlier
    ];

    // Dominant = Middlesex NJ (5 towns); PA outlier dropped; capped to 4 by appearance order.
    $sel = LocalTownCoherence::select($matched, 4);
    expect(collect($sel)->pluck('key')->all())->toBe(['a', 'b', 'c', 'd']);
});
