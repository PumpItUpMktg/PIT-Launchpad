<?php

use App\Build\PageMaterializer;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\LocationLandingSync;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function landingPages(Site $site)
{
    return Content::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->where('page_type', PageType::Location->value)
        ->whereNotNull('location_id')
        ->get();
}

it('creates one landing/hub page per base location that has something to say', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    // Two real locations (city / served towns) → each earns a landing page.
    $montclair = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair office',
        'address_components' => [['types' => ['locality'], 'long_name' => 'Montclair', 'short_name' => 'Montclair'], ['types' => ['administrative_area_level_1'], 'long_name' => 'New Jersey', 'short_name' => 'NJ']],
        'served_towns' => [['name' => 'Verona', 'state' => 'NJ']],
    ]);
    $trooper = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Trooper office',
        'address_components' => [['types' => ['locality'], 'long_name' => 'Trooper', 'short_name' => 'Trooper'], ['types' => ['administrative_area_level_1'], 'long_name' => 'Pennsylvania', 'short_name' => 'PA']],
        'served_towns' => [['name' => 'Norristown', 'state' => 'PA']],
    ]);
    // An empty location (no city, no towns) → nothing honest to say → skipped.
    $empty = Location::factory()->create([
        'site_id' => $site->id, 'name' => '', 'address_components' => [], 'served_towns' => [],
    ]);

    app(LocationLandingSync::class)->sync($site);

    $landings = landingPages($site);
    expect($landings)->toHaveCount(2)
        ->and($landings->pluck('location_id')->all())->toContain($montclair->id, $trooper->id)
        ->and($landings->pluck('location_id')->all())->not->toContain($empty->id)
        ->and($landings->firstWhere('location_id', $montclair->id)->title)->toBe('Montclair, NJ');
});

it('is idempotent — re-running never duplicates a location landing page', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair office',
        'address_components' => [['types' => ['locality'], 'long_name' => 'Montclair', 'short_name' => 'Montclair']],
        'served_towns' => [['name' => 'Verona', 'state' => 'NJ']],
    ]);

    app(LocationLandingSync::class)->sync($site);
    app(LocationLandingSync::class)->sync($site);
    app(LocationLandingSync::class)->sync($site);

    expect(landingPages($site))->toHaveCount(1);
});

it('runs in the build — materialize leaves a landing page pinned to each location', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Trooper office',
        'address_components' => [['types' => ['locality'], 'long_name' => 'Trooper', 'short_name' => 'Trooper']],
        'served_towns' => [['name' => 'Norristown', 'state' => 'PA']],
    ]);

    app(PageMaterializer::class)->materialize($site);

    expect(Content::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)
        ->where('kind', ContentKind::Page->value)
        ->where('location_id', $location->id)
        ->exists())->toBeTrue();
});
