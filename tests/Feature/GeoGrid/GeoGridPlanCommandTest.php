<?php

use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;

/** A GBP-backed, grid-ready location. */
function gridLocation(Site $site, array $extra = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id,
        'gbp_url' => 'https://maps.google/?cid='.random_int(1, 9999),
        'place_id' => 'ChIJ'.random_int(1000, 9999),
        'lat' => 40.7128, 'lng' => -74.0060,
    ], $extra));
}

it('reports locations × grid keywords × points and the total request count, no API', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    gridLocation($site);
    gridLocation($site);                                                   // 2 grid-ready locations
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => false]);  // not a grid keyword

    // 2 locations × 1 grid keyword × 49 points = 98 requests.
    $this->artisan('launchpad:geo-grid-plan', ['site' => $site->id])
        ->expectsOutputToContain('Grid-ready locations')
        ->expectsOutputToContain('98')
        ->assertExitCode(0);
});

it('previews a Local Falcon-style --radius by converting it to pin spacing', function () {
    config(['launchpad.geo_grid.grid_size' => 7]);
    $site = Site::factory()->create();
    // A location whose own spacing is 5.0 — proving --radius=10 overrides it to 3.33, not just echoing the default.
    gridLocation($site, ['name' => 'Montclair', 'grid_spacing_miles' => 5.0]);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);

    $this->artisan('launchpad:geo-grid-plan', ['site' => $site->id, '--radius' => 10])
        ->expectsOutputToContain('--radius override: 10.0 mi radius → 3.33 mi spacing')
        ->expectsOutputToContain('radius 10.0 mi / spacing 3.33 mi')
        ->assertExitCode(0);
});

it('flags a plan that exceeds the hard request ceiling', function () {
    config()->set('launchpad.geo_grid.request_ceiling', 50);   // one 49-point scan already tops it with 2 locations
    $site = Site::factory()->create();
    gridLocation($site);
    gridLocation($site);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);

    $this->artisan('launchpad:geo-grid-plan', ['site' => $site->id])
        ->expectsOutputToContain('EXCEEDS')
        ->assertExitCode(0);
});

it('says nothing to scan when there are no grid keywords', function () {
    $site = Site::factory()->create();
    gridLocation($site);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => false]);

    $this->artisan('launchpad:geo-grid-plan', ['site' => $site->id])
        ->expectsOutputToContain('Nothing to scan')
        ->assertExitCode(0);
});

it('ignores locations that are not grid-ready', function () {
    $site = Site::factory()->create();
    gridLocation($site);                                                   // ready
    Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=1', 'place_id' => null, 'lat' => 40.7, 'lng' => -74.0]); // no place_id
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);

    // 1 ready location × 1 keyword × 49 = 49.
    $this->artisan('launchpad:geo-grid-plan', ['site' => $site->id])
        ->expectsOutputToContain('49')
        ->assertExitCode(0);
});

it('fails clearly on an unknown site', function () {
    $this->artisan('launchpad:geo-grid-plan', ['site' => 'nope-nothing'])
        ->expectsOutputToContain('No site matches')
        ->assertExitCode(1);
});
