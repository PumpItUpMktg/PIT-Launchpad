<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Locations\Diagnostics\LocationAudit;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;

/** A published location page. Town = parent_location_id set; hub = location_id set. */
function locAuditPage(Site $site, array $attrs): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'status' => ContentStatus::Published,
        'wp_post_id' => random_int(1, 99999),
    ], $attrs));
}

function locAuditCoverage(Site $site, string $name, string $state, string $geoId, array $sourceIds): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => $state, 'geo_id' => $geoId,
        'size_tier' => 'large', 'population' => 40000, 'source_location_ids' => $sourceIds,
    ]);
}

it('flags a town page whose parent does not serve its county, and names the correct parent + cost', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);

    $trooper = Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'county_geoids' => ['42091'], 'home_county_geoid' => '42091']);
    locAuditPage($site, ['location_id' => $trooper->id, 'title' => 'Trooper, PA', 'slug' => 'trooper-pa']); // Trooper's hub
    $bedminster = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bedminster', 'county_geoids' => ['34035'], 'home_county_geoid' => '34035']);
    locAuditPage($site, ['location_id' => $bedminster->id, 'title' => 'Bedminster, NJ', 'slug' => 'bedminster-nj']); // Bedminster's hub

    // The cross-state collision: Montgomery NJ (Somerset 34035) nested under Trooper (Montgomery PA 42091).
    locAuditPage($site, ['parent_location_id' => $trooper->id, 'title' => 'Montgomery, NJ', 'slug' => 'trooper-pa/montgomery-nj']);
    locAuditCoverage($site, 'Montgomery', 'NJ', '3403545660', [$trooper->id]);
    // A correctly-parented town under Trooper (Montgomery PA, actually in 42091) — must NOT be flagged.
    locAuditPage($site, ['parent_location_id' => $trooper->id, 'title' => 'Norristown, PA', 'slug' => 'trooper-pa/norristown-pa']);
    locAuditCoverage($site, 'Norristown', 'PA', '4209154656', [$trooper->id]);

    $drift = app(LocationAudit::class)->townAssignmentDrift();

    expect($drift)->toHaveCount(1);
    $row = $drift[0];
    expect($row['town'])->toBe('Montgomery, NJ')
        ->and($row['town_county_geoid'])->toBe('34035')
        ->and($row['current_parent'])->toBe('Trooper')
        ->and($row['correct_parent'])->toBe('Bedminster')                       // the location that serves Somerset
        ->and($row['current_url'])->toBe('https://spg.test/trooper-pa/montgomery-nj')
        ->and($row['proposed_url'])->toBe('https://spg.test/bedminster-nj/montgomery-nj') // re-nested under the right hub
        ->and($row)->toHaveKeys(['indexed', 'inbound_links']);
});

it('reports a location whose served counties exclude its home county, with the pages under it (Spring City)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);

    // Spring City: physically Chester (42029) but serving Lehigh (42077) — its town pages came from the wrong county.
    $springCity = Location::factory()->create(['site_id' => $site->id, 'name' => 'Spring City', 'home_county_geoid' => '42029', 'county_geoids' => ['42077']]);
    locAuditPage($site, ['parent_location_id' => $springCity->id, 'title' => 'Allentown, PA', 'slug' => 'spring-city-pa/allentown-pa']);
    locAuditCoverage($site, 'Allentown', 'PA', '4207702000', [$springCity->id]);

    $rows = app(LocationAudit::class)->countyMismatches();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['location'])->toBe('Spring City')
        ->and($rows[0]['home_county_geoid'])->toBe('42029')
        ->and($rows[0]['served_county_geoids'])->toBe(['42077'])
        ->and($rows[0]['pages'])->toHaveCount(1)
        ->and($rows[0]['pages'][0]['town'])->toBe('Allentown, PA');
});

it('does not flag a location that serves its own home county', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'home_county_geoid' => '42091', 'county_geoids' => ['42091', '42077']]);

    expect(app(LocationAudit::class)->countyMismatches())->toBe([]);
});

it('flags town names that exist in more than one state', function () {
    $site = Site::factory()->create();
    locAuditCoverage($site, 'Montgomery', 'NJ', '3403545660', []);
    locAuditCoverage($site, 'Montgomery', 'PA', '4209154660', []);
    locAuditCoverage($site, 'Allentown', 'PA', '4207702000', []); // single-state → not flagged

    $rows = app(LocationAudit::class)->sameNameAcrossStates();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Montgomery')
        ->and($rows[0]['states'])->toEqualCanonicalizing(['NJ', 'PA']);
});

it('reports a partial-insert duplicate and removes it only via removeDuplicate — never a real row', function () {
    $site = Site::factory()->create();
    $real = Location::factory()->create(['site_id' => $site->id, 'name' => 'Roslyn', 'address' => '123 Main St', 'county_geoids' => ['42091'], 'is_storefront' => true]);
    $stub = Location::factory()->create(['site_id' => $site->id, 'name' => 'Roslyn', 'address' => '123 Main St', 'county_geoids' => [], 'is_storefront' => false]);

    $audit = app(LocationAudit::class);
    $dups = $audit->duplicateLocations();

    expect($dups)->toHaveCount(1)
        ->and($dups[0]['duplicate_id'])->toBe((string) $stub->id)
        ->and($dups[0]['survivor_id'])->toBe((string) $real->id);

    // removeDuplicate refuses the real row, removes only the stub.
    expect($audit->removeDuplicate((string) $real->id))->toBeFalse()
        ->and(Location::find($real->id))->not->toBeNull()
        ->and($audit->removeDuplicate((string) $stub->id))->toBeTrue()
        ->and(Location::find($stub->id))->toBeNull();
});
