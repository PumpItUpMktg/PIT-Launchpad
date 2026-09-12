<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Service;
use App\Models\SiloBlueprint;
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

it('a landing whose pin is unresolvable titles from its own "{City}, {ST}" identity, not the hallucinated stored title', function () {
    $site = locTitleSite();
    locTitlePillar($site);

    // A storefront/market landing (page_type=Location) with NO resolvable location_id — the drafter
    // clobbered meta.seo.title to a generic, geography-less service phrase. The factory title survives.
    $landing = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => null,
        'title' => 'Hoboken, NJ', 'slug' => 'hoboken-nj',
        'meta' => ['seo' => ['title' => 'Sump & sewage pump service and replacement', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($landing->fresh()))
        ->toBe('Sump Pump Services in Hoboken, NJ | Sump Pump Gurus');
});

it('an unresolvable-pin landing whose stored title already names its place keeps that stored title', function () {
    $site = locTitleSite();
    locTitlePillar($site);

    // The stored SEO title already leads with the place — a good hand/factory title — so it is preserved,
    // NOT replaced by the generic pillar composition. The rescue fires only for a title that drops the place.
    $landing = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => null,
        'title' => 'Hoboken, NJ', 'slug' => 'hoboken-nj',
        'meta' => ['seo' => ['title' => 'Emergency Sump Pump Repair in Hoboken, NJ', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($landing->fresh()))
        ->toBe('Emergency Sump Pump Repair in Hoboken, NJ | Sump Pump Gurus');
});

it('with neither a service nor a captured trade the deterministic title names the authoritative place alone', function () {
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

it('falls back to the site trade noun when no pillar service is designated, so the title still leads with the service', function () {
    $site = locTitleSite();
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);
    locTitleCoverage($site, $parent, '3401700009', 'Bayonne');
    // No pillar Service, no primary_service_id — only the owner-interview trade noun (the H1's source).
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'sump pump service']);

    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401700009',
        'title' => 'Bayonne, NJ', 'slug' => 'bayonne-nj',
        'meta' => ['seo' => ['title' => 'x', 'meta_description' => 'x']],
    ]);

    expect(app(MetaBlobAssembler::class)->documentTitle($town->fresh()))
        ->toBe('Sump pump service in Bayonne, NJ | Sump Pump Gurus');
});

it('keeps the town when a long comma-joined trade would otherwise be truncated past it', function () {
    $site = locTitleSite();
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office']);
    locTitleCoverage($site, $parent, '3401700055', 'Neptune');
    // No pillar service → the captured trade noun is used, and SPG's is a long, comma-joined phrase.
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'sump & sewage pump service and replacement, basement waterproofing']);

    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401700055',
        'title' => 'Neptune, NJ', 'slug' => 'neptune-nj',
        'meta' => ['seo' => ['title' => 'Sump & sewage pump service and replacement', 'meta_description' => 'x']],
    ]);

    // The trade shortens to its first clause so the town survives — never the reverse (the bug dropped
    // " in Neptune, NJ" and kept the trade alone).
    expect(app(MetaBlobAssembler::class)->documentTitle($town->fresh()))
        ->toBe('Sump & sewage pump service and replacement in Neptune, NJ | Sump Pump Gurus');
});

it('drops the trade to the bare place when even its first clause plus a long town exceeds the cap', function () {
    $site = locTitleSite();
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Morris office']);
    locTitleCoverage($site, $parent, '3403300077', 'Parsippany-Troy Hills');
    SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'sump & sewage pump service and replacement, basement waterproofing']);

    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3403300077',
        'title' => 'Parsippany-Troy Hills, NJ', 'slug' => 'parsippany-troy-hills-nj',
        'meta' => ['seo' => ['title' => 'x', 'meta_description' => 'x']],
    ]);

    // No trade form fits alongside the long town, so the title is the place alone — the town is kept.
    expect(app(MetaBlobAssembler::class)->documentTitle($town->fresh()))
        ->toBe('Parsippany-Troy Hills, NJ | Sump Pump Gurus');
});
