<?php

use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;

/** Bristol, PA: a borough and a township — two municipalities, one name. */
function bristolSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.example']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown']);

    foreach ([['4201708768', 'Bristol', 9700], ['4201708760', 'Bristol', 55500]] as [$geoId, $name, $pop]) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => $geoId, 'name' => $name,
            'population' => $pop, 'page_selected' => true, 'source_location_ids' => [$parent->id]]);
    }

    $page = Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $parent->id, 'primary_service_id' => null,
        'title' => 'Bristol, PA', 'slug' => 'bristol-pa', 'geo_id' => null, 'wp_post_id' => 4242,
    ]);

    return ['site' => $site, 'parent' => $parent, 'page' => $page];
}

it('lays out the evidence and names the free candidate', function () {
    ['site' => $site, 'parent' => $parent, 'page' => $page] = bristolSite();

    // The township is already held by a page that WAS plan-anchored — so this page can only be the borough.
    Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $parent->id, 'primary_service_id' => null,
        'title' => 'Bristol Township, PA', 'slug' => 'bristol-township-pa', 'geo_id' => '4201708760',
    ]);

    $this->artisan('launchpad:resolve-town-anchors', ['site' => 'sump'])
        ->expectsOutputToContain('ALREADY HELD by "Bristol Township, PA"')
        ->expectsOutputToContain('one candidate is free')
        ->expectsOutputToContain('--geo=4201708768')
        ->expectsOutputToContain('LIVE (wp 4242)')
        ->assertExitCode(0);
});

it('calls a page whose every candidate is taken what it is: a duplicate', function () {
    ['site' => $site, 'parent' => $parent] = bristolSite();

    foreach ([['4201708768', 'Bristol Borough, PA'], ['4201708760', 'Bristol Township, PA']] as [$geoId, $title]) {
        Content::factory()->page()->create([
            'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
            'parent_location_id' => $parent->id, 'primary_service_id' => null,
            'title' => $title, 'slug' => str($title)->slug()->value(), 'geo_id' => $geoId,
        ]);
    }

    $this->artisan('launchpad:resolve-town-anchors', ['site' => 'sump'])
        ->expectsOutputToContain('this one is a DUPLICATE, not an anchor problem')
        ->assertExitCode(0);
});

it('writes one decision, and refuses to point two pages at one town', function () {
    ['site' => $site, 'parent' => $parent, 'page' => $page] = bristolSite();

    $this->artisan('launchpad:resolve-town-anchors', ['site' => 'sump', '--page' => $page->id, '--geo' => '4201708768'])
        ->expectsOutputToContain('Anchored "Bristol, PA" to Bristol [4201708768]')
        ->assertExitCode(0);
    expect($page->fresh()->geo_id)->toBe('4201708768');

    $other = Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $parent->id, 'primary_service_id' => null,
        'title' => 'Bristol again', 'slug' => 'bristol-again', 'geo_id' => null,
    ]);

    // Two pages claiming one town is the state the whole GEOID chain exists to prevent.
    $this->artisan('launchpad:resolve-town-anchors', ['site' => 'sump', '--page' => $other->id, '--geo' => '4201708768'])
        ->expectsOutputToContain('already held by "Bristol, PA"')
        ->assertExitCode(1);
    expect($other->fresh()->geo_id)->toBeNull();
});
