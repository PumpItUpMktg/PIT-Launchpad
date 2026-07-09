<?php

use App\Support\BusinessHours;

it('expands a stored map into 7 ordered rows, filling missing days as closed', function () {
    $rows = BusinessHours::fromStored(['mon' => ['open' => '08:00', 'close' => '17:00'], 'sun' => 'closed']);

    expect($rows)->toHaveCount(7)
        ->and($rows[0])->toMatchArray(['day' => 'mon', 'closed' => false, 'open' => '08:00', 'close' => '17:00'])
        ->and($rows[1]['day'])->toBe('tue')
        ->and($rows[1]['closed'])->toBeTrue()          // missing → closed
        ->and($rows[6])->toMatchArray(['day' => 'sun', 'closed' => true]);
});

it('collapses rows back to the stored map', function () {
    $rows = [
        ['day' => 'mon', 'closed' => false, 'open' => '08:00', 'close' => '17:00'],
        ['day' => 'tue', 'closed' => true, 'open' => null, 'close' => null],
        ['day' => 'wed', 'closed' => false, 'open' => '', 'close' => ''], // no open → closed
    ];

    expect(BusinessHours::toStored($rows))->toBe([
        'mon' => ['open' => '08:00', 'close' => '17:00'],
        'tue' => 'closed',
        'wed' => 'closed',
    ]);
});

it('round-trips a stored map losslessly', function () {
    $stored = ['mon' => ['open' => '09:00', 'close' => '18:00'], 'sat' => 'closed', 'sun' => 'closed'];
    $back = BusinessHours::toStored(BusinessHours::fromStored($stored));

    expect($back['mon'])->toBe(['open' => '09:00', 'close' => '18:00'])
        ->and($back['sat'])->toBe('closed');
});

it('round-trips a 24h day as "24h", never 00:00–23:59', function () {
    $rows = BusinessHours::fromStored(['mon' => '24h', 'sun' => 'closed']);

    expect($rows[0])->toMatchArray(['day' => 'mon', 'closed' => false, 'all_day' => true, 'open' => null])
        ->and($rows[6])->toMatchArray(['day' => 'sun', 'all_day' => false, 'closed' => true]);

    expect(BusinessHours::toStored($rows))->toMatchArray(['mon' => '24h', 'sun' => 'closed']);
});

it('always-open sets every day to 24h', function () {
    $rows = BusinessHours::alwaysOpen();

    expect($rows)->toHaveCount(7)
        ->and(collect($rows)->every(fn ($r) => $r['all_day'] === true && ! $r['closed']))->toBeTrue()
        ->and(BusinessHours::toStored($rows))->toBe(array_fill_keys(BusinessHours::DAYS, '24h'));
});

it('applies the first open day to every day for "same every day"', function () {
    $rows = BusinessHours::fromStored(['tue' => ['open' => '10:00', 'close' => '16:00']]);

    $same = BusinessHours::sameEveryDay($rows);

    expect($same)->toHaveCount(7)
        ->and(collect($same)->every(fn ($r) => $r['open'] === '10:00' && $r['close'] === '16:00' && ! $r['closed']))->toBeTrue();
});

it('displayRows collapses identical consecutive days into a corporate am/pm range', function () {
    $week = [];
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $d) {
        $week[$d] = ['open' => '08:00', 'close' => '18:00'];
    }
    $week['sun'] = 'closed';

    expect(BusinessHours::displayRows($week))
        ->toBe([['label' => 'Monday – Saturday', 'value' => '8am – 6pm']]);
});

it('displayRows splits ranges honestly on a closed gap and converts edge times', function () {
    $rows = BusinessHours::displayRows([
        'mon' => ['open' => '08:30', 'close' => '17:00'],
        'tue' => ['open' => '08:30', 'close' => '17:00'],
        'wed' => 'closed',                                  // the gap breaks the run
        'thu' => ['open' => '08:30', 'close' => '17:00'],
        'fri' => ['open' => '00:00', 'close' => '12:00'],   // midnight + noon edges
        'sat' => '24h',
        'sun' => 'closed',
    ]);

    expect($rows)->toBe([
        ['label' => 'Monday – Tuesday', 'value' => '8:30am – 5pm'],
        ['label' => 'Thursday', 'value' => '8:30am – 5pm'],
        ['label' => 'Friday', 'value' => '12am – 12pm'],
        ['label' => 'Saturday', 'value' => 'Open 24 hours'],
    ]);
});

it('displayRows is empty when nothing is captured (the hours block data-gates)', function () {
    expect(BusinessHours::displayRows(null))->toBe([])
        ->and(BusinessHours::displayRows([]))->toBe([]);
});
