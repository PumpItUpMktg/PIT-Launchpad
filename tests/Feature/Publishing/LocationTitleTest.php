<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Service;
use App\Models\Site;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\MetaBlobAssembler;

function locTitleSite(): Site
{
    return Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
}

function locTitlePillar(Site $site, string $name = 'Sump Pump Services'): Service
{
    return Service::factory()->create(['site_id' => $site->id, 'name' => $name, 'silo_role' => ServiceSiloRole::Pillar]);
}

function locTitleCoverage(Site $site, Location $parent, string $geoId, string $name): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => $geoId, 'name' => $name, 'state' => 'NJ',
        'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
    ]);
}

it('an anchored TOWN page titles deterministically from the CoverageArea town, ignoring a hallucinated stored title', function () {
    $site = locTitleSite();
    locTitlePillar($site);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);
    locTitleCoverage($site, $parent, '3401799999', 'Neptune');

    // The stored title hallucinated the WRONG town; the page is anchored to Neptune by geo_id.
    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401799999',
        'title' => 'Allentown, PA', 'slug' => 'neptune-nj',
        'meta' => ['seo' => ['title' => 'Sump Pump & Basement Water Services in Allentown, PA', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($town->fresh()))
        ->toBe('Sump Pump Services in Neptune, NJ | Sump Pump Gurus');
});

it('the anchored town render names the authoritative town in the H1/coverage, never the hallucinated one', function () {
    $site = locTitleSite();
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);
    locTitleCoverage($site, $parent, '3401799999', 'Neptune');

    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401799999',
        'title' => 'Allentown, PA', 'slug' => 'neptune-nj', 'slot_payload' => [],
    ]);

    $markup = app(BlockContentAssembler::class)->compose($town->fresh(), [], []);

    // Before the fix the H1/intro derived the town from the (wrong) title; now it reads the authoritative one.
    expect($markup)->toBeString()
        ->toContain('Neptune')
        ->not->toContain('Allentown');
});

it('an un-anchored TOWN page (no geo_id) keeps its stored title — never laundered through a name guess', function () {
    $site = locTitleSite();
    locTitlePillar($site);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);

    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => null,
        'title' => 'Somewhere, NJ', 'slug' => 'somewhere-nj',
        'meta' => ['seo' => ['title' => 'Sump Pump Repair in Somewhere, NJ', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($town->fresh()))
        ->toBe('Sump Pump Repair in Somewhere, NJ | Sump Pump Gurus');
});

it('a HUB page titles deterministically from its own Location city/state', function () {
    $site = locTitleSite();
    locTitlePillar($site);
    $hub = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Hackensack office',
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Hackensack', 'short_name' => 'Hackensack'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'New Jersey', 'short_name' => 'NJ'],
        ],
    ]);

    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $hub->id, 'parent_location_id' => null,
        'title' => 'Hackensack, NJ', 'slug' => 'hackensack-nj',
        'meta' => ['seo' => ['title' => 'Sump Pump Service & Basement Waterproofing in Hackensack, NJ', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($page->fresh()))
        ->toBe('Sump Pump Services in Hackensack, NJ | Sump Pump Gurus');
});

it('with no pillar service the deterministic title names the authoritative place alone', function () {
    $site = locTitleSite();
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);
    locTitleCoverage($site, $parent, '3401700001', 'Neptune');

    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401700001',
        'title' => 'Wrongtown, PA', 'slug' => 'neptune-nj',
        'meta' => ['seo' => ['title' => 'x', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($town->fresh()))
        ->toBe('Neptune, NJ | Sump Pump Gurus');
});
