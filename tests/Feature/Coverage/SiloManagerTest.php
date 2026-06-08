<?php

use App\Models\Keyword;
use App\Models\Silo;
use App\Models\Site;
use App\Operator\Coverage\SiloManager;

test('a silo below the keyword-support floor is flagged thin', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->count(2)->create(['site_id' => $site->id, 'silo_id' => $silo->id]);

    $manager = app(SiloManager::class);

    expect($manager->supportCount($silo))->toBe(2)
        ->and($manager->isViable($silo))->toBeFalse()
        ->and($manager->viabilityWarning($silo))->toContain('Thin silo');
});

test('a silo at or above the floor clears the viability guard', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->count(3)->create(['site_id' => $site->id, 'silo_id' => $silo->id]);

    $manager = app(SiloManager::class);

    expect($manager->isViable($silo))->toBeTrue()
        ->and($manager->viabilityWarning($silo))->toBeNull();
});
