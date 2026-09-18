<?php

use App\Locations\CoverageMapSvg;

/** A county box with a base pin inside it and one hand-added town. */
function coverageShapes(): array
{
    return [
        'polygons' => [[
            'geo_id' => '42017', 'name' => 'Bucks',
            'rings' => [[
                ['lat' => 40.6, 'lng' => -75.2], ['lat' => 40.6, 'lng' => -74.9],
                ['lat' => 40.2, 'lng' => -74.9], ['lat' => 40.2, 'lng' => -75.2],
                ['lat' => 40.6, 'lng' => -75.2],
            ]],
        ]],
        'pins' => [['name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13, 'color' => '#2563eb']],
        'manual' => [['name' => 'Warrington', 'lat' => 40.25, 'lng' => -75.15]],
    ];
}

it('projects counties, base pins and hand-added towns into one frame', function () {
    ['polygons' => $p, 'pins' => $pins, 'manual' => $manual] = coverageShapes();

    $map = CoverageMapSvg::build($p, $pins, $manual);

    expect($map['counties'])->toHaveCount(1)
        ->and($map['counties'][0]['name'])->toBe('Bucks')
        ->and($map['counties'][0]['paths'][0])->toStartWith('M')->toEndWith('Z')
        ->and($map['pins'])->toHaveCount(1)
        ->and($map['pins'][0]['color'])->toBe('#2563eb')
        ->and($map['flags'])->toHaveCount(1)
        ->and($map['flags'][0]['name'])->toBe('Warrington');

    // One frame over everything: the base pin sits INSIDE the county it serves, and north is up.
    expect($map['pins'][0]['x'])->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($map['pins'][0]['y'])->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($map['pins'][0]['y'])->toBeLessThan($map['flags'][0]['y']);   // 40.31 is north of 40.25
});

it('draws nothing rather than an empty box', function () {
    expect(CoverageMapSvg::build([], [], []))->toBeNull()
        // A single located base with no counties is a dot on a blank field, not a map.
        ->and(CoverageMapSvg::build([], [['name' => 'Base', 'lat' => 40.3, 'lng' => -75.1]], []))->toBeNull();

    // A county alone still draws — that IS the coverage picture before any base is located.
    expect(CoverageMapSvg::build(coverageShapes()['polygons'], [], []))->not->toBeNull();
});

it('skips a pin with no coordinates instead of dropping it at the origin', function () {
    $map = CoverageMapSvg::build(coverageShapes()['polygons'], [
        ['name' => 'Located', 'lat' => 40.3, 'lng' => -75.1, 'color' => '#16a34a'],
        ['name' => 'Not geocoded yet', 'lat' => null, 'lng' => null],
    ], []);

    expect($map['pins'])->toHaveCount(1)->and($map['pins'][0]['name'])->toBe('Located');
});
