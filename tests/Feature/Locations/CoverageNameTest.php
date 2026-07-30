<?php

use App\Locations\CoverageName;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('CoverageName::clean strips the leading numbered-list artifact, keeps legitimate names', function () {
    expect(CoverageName::clean('6, Havre de Grace'))->toBe('Havre de Grace')
        ->and(CoverageName::clean('2, Halls Cross Roads'))->toBe('Halls Cross Roads')
        ->and(CoverageName::clean('4. Marshall'))->toBe('Marshall')      // period-form list marker
        ->and(CoverageName::clean('Havre de Grace'))->toBe('Havre de Grace') // already clean
        ->and(CoverageName::clean('29 Palms'))->toBe('29 Palms')          // number-led, no separator → untouched
        ->and(CoverageName::clean('6,'))->toBe('6,');                     // degrade: don't empty it
});

test('CoverageArea normalizes the name on write — no path can store the artifact', function () {
    $site = Site::factory()->create();
    $area = CoverageArea::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'geo_id' => '1', 'name' => '6, Havre de Grace', 'type' => 'place', 'state' => 'MD', 'source' => 'county',
    ]);

    expect($area->fresh()->name)->toBe('Havre de Grace');
});

test('launchpad:clean-coverage-names fixes coverage + served-town names on --apply', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    // Write the dirty names straight to the DB (bypass the model mutator) so the sweep has something to fix.
    $id = (string) Str::ulid();
    DB::table('coverage_areas')->insert([
        'id' => $id, 'site_id' => $site->id, 'geo_id' => '1', 'name' => '6, Havre de Grace',
        'type' => 'place', 'state' => 'MD', 'source' => 'county', 'page_selected' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'served_towns' => [['name' => '2, Halls Cross Roads', 'state' => 'MD']]]);

    // Preview changes nothing.
    Artisan::call('launchpad:clean-coverage-names', ['site' => 'SPG']);
    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->whereKey($id)->value('name'))->toBe('6, Havre de Grace');

    // Apply fixes both the coverage row and the served-town entry.
    Artisan::call('launchpad:clean-coverage-names', ['site' => 'SPG', '--apply' => true]);
    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->whereKey($id)->value('name'))->toBe('Havre de Grace')
        ->and($loc->fresh()->served_towns[0]['name'])->toBe('Halls Cross Roads');
});
