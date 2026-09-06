<?php

use App\Operate\BlogBoard;

/** A minimal candidate row (only the fields group() reads). */
function grpRow(string $id, ?string $silo, ?string $scope): array
{
    return ['id' => $id, 'silo' => $silo, 'scope' => $scope, 'title' => "T{$id}"];
}

it('groups candidates by silo, local-first within each, biggest backlog first', function () {
    $rows = [
        grpRow('1', 'Sump Pumps', 'general'),
        grpRow('2', 'Sump Pumps', 'local'),
        grpRow('3', 'Sump Pumps', 'general'),
        grpRow('4', 'Waterproofing', null),
        grpRow('5', null, 'general'), // no silo → its own group
    ];

    $groups = app(BlogBoard::class)->group($rows, cap: 8);

    // Ordered by total desc: Sump Pumps (3) → Waterproofing (1) / No silo (1).
    expect(array_column($groups, 'silo'))->toBe(['Sump Pumps', 'Waterproofing', '— No silo —']);

    $sump = $groups[0];
    expect($sump['total'])->toBe(3)
        ->and($sump['local'])->toBe(1)
        ->and($sump['overflow'])->toBe(0)
        // Local first (id 2), then the two general rows in their incoming order (stable).
        ->and(array_column($sump['visible'], 'id'))->toBe(['2', '1', '3']);
});

it('caps the visible rows per group and reports the overflow', function () {
    $rows = [
        grpRow('1', 'Sump Pumps', 'local'),
        grpRow('2', 'Sump Pumps', 'general'),
        grpRow('3', 'Sump Pumps', 'general'),
        grpRow('4', 'Sump Pumps', 'general'),
    ];

    $groups = app(BlogBoard::class)->group($rows, cap: 2);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['total'])->toBe(4)
        ->and($groups[0]['overflow'])->toBe(2)
        ->and(array_column($groups[0]['visible'], 'id'))->toBe(['1', '2']); // local first, then next by order
});

it('cap 0 shows every row (no overflow)', function () {
    $rows = [grpRow('1', 'A', 'local'), grpRow('2', 'A', 'general'), grpRow('3', 'A', 'general')];

    $groups = app(BlogBoard::class)->group($rows, cap: 0);

    expect($groups[0]['total'])->toBe(3)
        ->and($groups[0]['overflow'])->toBe(0)
        ->and($groups[0]['visible'])->toHaveCount(3);
});
