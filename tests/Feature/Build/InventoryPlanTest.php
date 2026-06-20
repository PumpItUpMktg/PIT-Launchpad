<?php

use App\Build\InventoryPlan;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Models\CoverageArea;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

test('the inventory groups service pages by silo with hub / sub-hub / page, keyword, and covers', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $mk = fn (array $a) => Spoke::factory()->create(array_merge(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'status' => SpokeStatus::Offered], $a));

    $sump = $mk(['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true, 'primary_keyword' => 'sump pumps']);
    $install = $mk(['silo' => 'Sump Pumps', 'name' => 'Install', 'granularity' => SpokeGranularity::OwnPage, 'primary_keyword' => 'sump pump installation', 'volume' => 300]);
    $mk(['silo' => 'Sump Pumps', 'name' => 'Water-Powered Backup', 'granularity' => SpokeGranularity::Folded, 'fold_into_id' => $install->id]);
    $mk(['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true, 'is_sub_hub' => true, 'parent_silo_id' => $sump->id, 'primary_keyword' => 'backup power']);
    $mk(['silo' => 'Backup Power', 'name' => 'Battery Backup', 'granularity' => SpokeGranularity::OwnPage, 'primary_keyword' => 'battery backup']);

    $inv = app(InventoryPlan::class)->for($site);

    // Foundation = 6 fixed + 2 always-offerable optionals (Why Choose Us, FAQ); service = 4.
    expect($inv['counts'])->toBe(['total' => 12, 'foundation' => 8, 'service' => 4, 'location_now' => 0, 'reserve' => 0])
        ->and(collect($inv['foundation'])->firstWhere('label', 'Home')['kind'])->toBe('core')
        ->and(collect($inv['foundation'])->firstWhere('label', 'Privacy Policy')['kind'])->toBe('legal')
        ->and(collect($inv['foundation'])->firstWhere('label', 'FAQ')['kind'])->toBe('optional')
        ->and($inv['silos'])->toHaveCount(1);                       // Backup Power rolls up as a sub-hub

    $silo = $inv['silos'][0];
    expect($silo['name'])->toBe('Sump Pumps')
        ->and($silo['hub']['keyword'])->toBe('sump pumps')
        ->and($silo['hub']['type'])->toBe('hub')
        ->and(collect($silo['pages'])->firstWhere('name', 'Install')['covers'])->toBe(['Water-Powered Backup'])
        ->and($silo['subhubs'])->toHaveCount(1)
        ->and($silo['subhubs'][0]['name'])->toBe('Backup Power')
        ->and($silo['subhubs'][0]['type'])->toBe('sub-hub')
        ->and(collect($silo['subhubs'][0]['pages'])->pluck('name'))->toContain('Battery Backup');
});

test('location pages are grouped by tier with the reserve count', function () {
    $site = Site::factory()->create();
    SiloBlueprint::factory()->create(['site_id' => $site->id]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'size_tier' => 'major', 'page_selected' => true, 'population' => 300000]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Nutley', 'size_tier' => 'medium', 'page_selected' => true, 'population' => 28000]);
    CoverageArea::factory()->count(3)->create(['site_id' => $site->id, 'size_tier' => 'small', 'page_selected' => false]);

    $inv = app(InventoryPlan::class)->for($site);

    expect($inv['counts']['location_now'])->toBe(2)
        ->and($inv['counts']['reserve'])->toBe(3)
        ->and(collect($inv['tiers'])->pluck('label'))->toContain('Major')->toContain('Medium')
        ->and(collect($inv['tiers'])->firstWhere('label', 'Major')['towns'])->toBe(['Newark']);
});
