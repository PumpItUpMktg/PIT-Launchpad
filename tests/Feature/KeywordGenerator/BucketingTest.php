<?php

use App\KeywordGenerator\Bucketer;
use App\Models\Silo;
use App\Models\Site;

test('a query buckets into the silo whose rule_set includes it', function () {
    $site = Site::factory()->create();

    $plumbing = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'Plumbing',
        'rule_set' => ['include_patterns' => ['water heater', 'drain', 'pipe'], 'exclude_patterns' => []],
    ]);
    $hvac = Silo::factory()->create([
        'site_id' => $site->id,
        'name' => 'HVAC',
        'rule_set' => ['include_patterns' => ['furnace', 'air conditioner', 'hvac'], 'exclude_patterns' => []],
    ]);

    $silos = collect([$plumbing, $hvac]);
    $bucketer = new Bucketer;

    expect($bucketer->bucket('water heater repair cost', $silos)->id)->toBe($plumbing->id)
        ->and($bucketer->bucket('furnace not heating', $silos)->id)->toBe($hvac->id);
});

test('an excluded pattern keeps a query out of a silo', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'rule_set' => ['include_patterns' => ['water heater'], 'exclude_patterns' => ['commercial']],
    ]);

    expect((new Bucketer)->bucket('commercial water heater install', collect([$silo])))->toBeNull();
});

test('an unbucketed query is the gap signal', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create([
        'site_id' => $site->id,
        'rule_set' => ['include_patterns' => ['plumbing'], 'exclude_patterns' => []],
    ]);

    expect((new Bucketer)->bucket('roof replacement', collect([$silo])))->toBeNull();
});
