<?php

use App\Locations\PopulationBucket;

test('it buckets by the configured thresholds (Large > 25k, Medium >= 15k, else Small)', function () {
    expect(PopulationBucket::for(40000))->toBe(PopulationBucket::Large)
        ->and(PopulationBucket::for(25001))->toBe(PopulationBucket::Large)
        ->and(PopulationBucket::for(25000))->toBe(PopulationBucket::Medium)  // boundary: not > large
        ->and(PopulationBucket::for(15000))->toBe(PopulationBucket::Medium)  // inclusive floor
        ->and(PopulationBucket::for(14999))->toBe(PopulationBucket::Small)
        ->and(PopulationBucket::for(2200))->toBe(PopulationBucket::Small)
        ->and(PopulationBucket::for(null))->toBe(PopulationBucket::Unknown); // ACS miss / no key
});

test('thresholds are overridable', function () {
    $t = ['large' => 50000, 'medium' => 20000];

    expect(PopulationBucket::for(40000, $t))->toBe(PopulationBucket::Medium)
        ->and(PopulationBucket::for(60000, $t))->toBe(PopulationBucket::Large)
        ->and(PopulationBucket::for(10000, $t))->toBe(PopulationBucket::Small);
});
