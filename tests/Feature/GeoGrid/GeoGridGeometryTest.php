<?php

use App\GeoGrid\GeoGridGeometry;

it('produces grid_size² points with the center cell exactly on the center coordinate', function () {
    $points = app(GeoGridGeometry::class)->points(40.7128, -74.0060, 7, 1.5);

    expect($points)->toHaveCount(49);

    $center = collect($points)->firstWhere(fn ($p) => $p['row'] === 3 && $p['col'] === 3);
    expect($center['lat'])->toBe(40.7128)
        ->and($center['lng'])->toBe(-74.0060);
});

it('steps latitude by spacing/69 and longitude by more, derived from the center latitude', function () {
    $lat = 40.7128;
    $points = app(GeoGridGeometry::class)->points($lat, -74.0060, 7, 1.5);

    $latStep = 1.5 / 69.0;
    $lngStep = 1.5 / (69.0 * cos(deg2rad($lat)));

    // One row north of center → +latStep; one col east of center → +lngStep.
    $north = collect($points)->firstWhere(fn ($p) => $p['row'] === 4 && $p['col'] === 3);
    $east = collect($points)->firstWhere(fn ($p) => $p['row'] === 3 && $p['col'] === 4);

    expect($north['lat'])->toEqualWithDelta(40.7128 + $latStep, 1e-9)
        ->and($east['lng'])->toEqualWithDelta(-74.0060 + $lngStep, 1e-9);

    // The longitude step is LARGER than the latitude step at NJ latitude (a lng degree is shorter than a
    // lat degree), so a hardcoded single step would skew the grid.
    expect($lngStep)->toBeGreaterThan($latStep);
});

it('rejects an even grid size and non-positive spacing', function () {
    $g = app(GeoGridGeometry::class);
    expect(fn () => $g->points(40.7, -74.0, 6, 1.5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $g->points(40.7, -74.0, 7, 0.0))->toThrow(InvalidArgumentException::class);
});

it('counts points per scan as the square of the grid size', function () {
    expect(app(GeoGridGeometry::class)->pointCount(7))->toBe(49);
});

it('converts a Local Falcon RADIUS to pin spacing and back (the footgun guard)', function () {
    // 7×7 = 3 steps center→edge. A 10mi radius ⇒ 10/3 spacing; a 1.5mi radius ⇒ 0.5 spacing.
    expect(GeoGridGeometry::spacingForRadius(10, 7))->toBe(10 / 3)
        ->and(GeoGridGeometry::spacingForRadius(1.5, 7))->toBe(0.5)
        ->and(GeoGridGeometry::radiusForSpacing(3.33, 7))->toBe(3.33 * 3)
        // Round-trips: radius → spacing → radius.
        ->and(GeoGridGeometry::radiusForSpacing(GeoGridGeometry::spacingForRadius(10, 7), 7))->toBe(10.0);

    // 3×3 = 1 step to the edge, so radius == spacing.
    expect(GeoGridGeometry::spacingForRadius(2.0, 3))->toBe(2.0);
});
