<?php

use App\ContentEngine\Generation\PageGenerator;
use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;

/** Seed one anchored foreign-town page (H1 names Allentown) and one OK page (H1 names its own town). */
function regenBodiesFixture(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'Sump Pump Gurus']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hub office']);

    $cov = function (string $geo, string $name) use ($site, $parent): void {
        CoverageArea::factory()->create([
            'site_id' => $site->id, 'geo_id' => $geo, 'name' => $name, 'state' => 'NJ',
            'type' => MunicipalityType::CountySubdivision, 'source_location_ids' => [$parent->id],
        ]);
    };
    $cov('3401700001', 'Neptune');
    $cov('3401700002', 'Weehawken');

    $page = function (string $geo, string $slug, string $title, string $h1) use ($site, $parent): void {
        Content::factory()->published()->create([
            'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
            'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => $geo,
            'title' => $title, 'slug' => $slug, 'slot_payload' => ['hero_headline' => $h1],
        ]);
    };
    $page('3401700001', 'neptune-nj', 'Neptune, NJ', 'Sump Pump Repair & Basement Waterproofing in Allentown, PA'); // foreign
    $page('3401700002', 'weehawken-nj', 'Weehawken, NJ', 'Sump Pump Repair in Weehawken, NJ');                        // ok

    return $site;
}

it('refuses to run without a set (--foreign / --weak)', function () {
    $site = regenBodiesFixture();

    $this->artisan('launchpad:regenerate-location-bodies --site='.$site->id)
        ->assertFailed()
        ->expectsOutputToContain('Choose a set');
});

it('dry-runs by default: lists the foreign set and regenerates nothing', function () {
    $site = regenBodiesFixture();

    // Bound so the command resolves it, but it must NOT be called in a dry run.
    $generator = Mockery::mock(PageGenerator::class);
    $generator->shouldNotReceive('generate');
    app()->instance(PageGenerator::class, $generator);

    $this->artisan('launchpad:regenerate-location-bodies --site='.$site->id.' --foreign')
        ->assertSuccessful()
        ->expectsOutputToContain('neptune-nj')
        ->expectsOutputToContain('DRY RUN');
});

it('--execute regenerates only the foreign-town page, not the OK one', function () {
    $site = regenBodiesFixture();

    $generator = Mockery::mock(PageGenerator::class);
    $generator->shouldReceive('generate')
        ->once()
        ->with(Mockery::on(fn (Content $p): bool => $p->slug === 'neptune-nj'))
        ->andReturnUsing(fn (Content $p): Content => $p);
    app()->instance(PageGenerator::class, $generator);

    $this->artisan('launchpad:regenerate-location-bodies --site='.$site->id.' --foreign --execute')
        ->assertSuccessful()
        ->expectsOutputToContain('Regenerated');
});
