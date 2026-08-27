<?php

use App\GeoGrid\GeoGridPalette;

it('buckets absolute rank into the heat language (top-3 strongest, absent greyed)', function () {
    expect(GeoGridPalette::absolute(1))->toBe('#15803d')      // top-3 green
        ->and(GeoGridPalette::absolute(3))->toBe('#15803d')
        ->and(GeoGridPalette::absolute(5))->toBe('#65a30d')   // 4–7
        ->and(GeoGridPalette::absolute(9))->toBe('#ca8a04')   // 8–10
        ->and(GeoGridPalette::absolute(12))->toBe('#c2410c')  // 11–15
        ->and(GeoGridPalette::absolute(20))->toBe('#c0392b')  // 16+
        ->and(GeoGridPalette::absolute(null))->toBe(GeoGridPalette::ABSENT);
});

it('colors delta by movement — improve green, slip red, new blue, lost dark-red', function () {
    expect(GeoGridPalette::delta(2, 5))->toBe('#15803d')      // 5→2 improved
        ->and(GeoGridPalette::delta(5, 2))->toBe('#c0392b')   // 2→5 slipped
        ->and(GeoGridPalette::delta(4, 4))->toBe(GeoGridPalette::ABSENT) // unchanged
        ->and(GeoGridPalette::delta(3, null))->toBe('#2563eb')          // newly ranking
        ->and(GeoGridPalette::delta(null, 3))->toBe('#7f1d1d')          // lost the point
        ->and(GeoGridPalette::delta(null, null))->toBe(GeoGridPalette::ABSENT);
});

it('reports signed movement only when both endpoints ranked', function () {
    expect(GeoGridPalette::move(2, 5))->toBe(3)               // improved by 3
        ->and(GeoGridPalette::move(5, 2))->toBe(-3)           // slipped by 3
        ->and(GeoGridPalette::move(3, null))->toBeNull()
        ->and(GeoGridPalette::move(null, 3))->toBeNull();
});
