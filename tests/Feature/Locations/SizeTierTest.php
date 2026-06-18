<?php

use App\Enums\SizeTier;
use App\Models\Site;

test('forPopulation buckets at the tenant thresholds (inclusive floors)', function () {
    $t = ['major' => 50000, 'large' => 30000, 'medium' => 15000];

    expect(SizeTier::forPopulation(60000, $t))->toBe(SizeTier::Major)
        ->and(SizeTier::forPopulation(50000, $t))->toBe(SizeTier::Major)
        ->and(SizeTier::forPopulation(49999, $t))->toBe(SizeTier::Large)
        ->and(SizeTier::forPopulation(30000, $t))->toBe(SizeTier::Large)
        ->and(SizeTier::forPopulation(15000, $t))->toBe(SizeTier::Medium)
        ->and(SizeTier::forPopulation(14999, $t))->toBe(SizeTier::Small)
        ->and(SizeTier::forPopulation(0, $t))->toBe(SizeTier::Small);
});

test('null population is ungrouped (no tier)', function () {
    expect(SizeTier::forPopulation(null, ['major' => 50000, 'large' => 30000, 'medium' => 15000]))->toBeNull();
});

test('defaults are 50k / 30k / 15k when no thresholds passed', function () {
    expect(SizeTier::forPopulation(50000))->toBe(SizeTier::Major)
        ->and(SizeTier::forPopulation(30000))->toBe(SizeTier::Large)
        ->and(SizeTier::forPopulation(29999))->toBe(SizeTier::Medium)
        ->and(SizeTier::forPopulation(14999))->toBe(SizeTier::Small);
});

test('Site::coverageThresholds merges per-site overrides over the config defaults', function () {
    $site = Site::factory()->make(['coverage_thresholds' => ['major' => 80000]]);

    expect($site->coverageThresholds())->toBe(['major' => 80000, 'large' => 30000, 'medium' => 15000]);

    $plain = Site::factory()->make(['coverage_thresholds' => null]);
    expect($plain->coverageThresholds())->toBe(['major' => 50000, 'large' => 30000, 'medium' => 15000]);
});
