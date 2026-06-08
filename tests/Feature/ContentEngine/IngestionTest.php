<?php

use App\ContentEngine\BackfillSplitter;
use App\ContentEngine\PreFilter;
use App\ContentEngine\SameStoryClusterer;
use Tests\Support\News;

test('the pre-filter drops junk and empty items', function () {
    $filter = new PreFilter;

    expect($filter->passes(News::item('Sponsored: best plumbing deals')))->toBeFalse()
        ->and($filter->passes(News::item('Hi')))->toBeFalse()
        ->and($filter->passes(News::item('Cold snap bursts pipes across the county')))->toBeTrue();
});

test('same-story clustering collapses multi-outlet coverage of one event', function () {
    $items = [
        News::item('Winter storm freezes pipes across Travis County', topic: 'storm-pipes', source: 'Tribune'),
        News::item('Pipes burst as winter storm hits Travis County homes', topic: 'storm-pipes', source: 'Statesman'),
        News::item('New tankless water heater rebate announced', topic: 'rebate', source: 'Tribune'),
    ];

    $clusters = (new SameStoryClusterer)->cluster($items);

    expect($clusters)->toHaveCount(2);
    $storm = collect($clusters)->first(fn ($c) => $c->representative->topic === 'storm-pipes');
    expect($storm->outletCount())->toBe(2)
        ->and($storm->sourceNames())->toContain('Tribune')
        ->and($storm->sourceNames())->toContain('Statesman');
});

test('the backfill splitter splits at the freshness cutoff', function () {
    $items = [
        News::item('Recent development', ageDays: 10),
        News::item('Old development', ageDays: 200),
    ];

    $split = (new BackfillSplitter)->split($items, cutoffDays: 90);

    expect($split['recent'])->toHaveCount(1)
        ->and($split['recent'][0]->title)->toBe('Recent development')
        ->and($split['archive'])->toHaveCount(1)
        ->and($split['archive'][0]->title)->toBe('Old development');
});
