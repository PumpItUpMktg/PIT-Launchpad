<?php

use App\Enums\ContentStatus;
use App\Enums\MunicipalityType;
use App\Filament\Console\Pages\BlogReview;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\OpsConsole\StorefrontTowns;
use Illuminate\Support\Facades\Cache;

/** A site with one storefront in Harford County, MD, covering Havre de Grace. */
function stSite(): array
{
    Cache::flush();
    app()->bind(MunicipalityGazetteer::class, fn () => new MockMunicipalityGazetteer(
        counties: [new County('24025', 'Harford County', '24', '025')],
    ));

    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $store = Location::factory()->create([
        'site_id' => $site->id, 'is_storefront' => true,
        'home_county_geoid' => '24025', 'county_geoids' => ['24025'],
    ]);
    CoverageArea::create([
        'site_id' => $site->id, 'geo_id' => '2402590500', 'name' => 'Havre de Grace',
        'type' => MunicipalityType::CountySubdivision, 'state' => 'MD', 'source' => 'county',
        'source_location_ids' => [$store->id],
    ]);

    return [$site, $store];
}

it('groups storefront towns by county with a resolved county name', function () {
    [$site] = stSite();

    $counties = app(StorefrontTowns::class)->counties($site);

    expect($counties)->toHaveCount(1)
        ->and($counties[0]['geoid'])->toBe('24025')
        ->and($counties[0]['name'])->toBe('Harford County')
        ->and(collect($counties[0]['towns'])->pluck('key')->all())->toBe(['havre de grace']);
});

it('a site with no storefront yields no counties', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'is_storefront' => false]);

    expect(app(StorefrontTowns::class)->counties($site))->toBe([]);
});

it('matches a published post by its town tag and a draft by its body text', function () {
    [$site] = stSite();
    $storefront = app(StorefrontTowns::class);
    $targets = $storefront->targetTowns($site, '24025', null);

    $published = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => 'A story']);
    ContentTown::create(['content_id' => $published->id, 'site_id' => $site->id, 'town' => 'havre de grace', 'town_display' => 'Havre de Grace']);

    $draft = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Sump help in Havre de Grace', 'body' => 'Serving Havre de Grace homeowners.']);
    $elsewhere = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'General tips', 'body' => 'No town named here.']);

    expect($storefront->postCovers($published, $targets))->toBeTrue()
        ->and($storefront->postCovers($draft, $targets))->toBeTrue()
        ->and($storefront->postCovers($elsewhere, $targets))->toBeFalse();
});

it('filters the Review page to posts covering the selected county', function () {
    [$site] = stSite();
    $this->actingAs(User::factory()->create());

    $inTown = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Havre de Grace basements', 'body' => 'Havre de Grace flooding advice.']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Generic', 'body' => 'Nothing local.']);

    $page = new BlogReview;
    $page->siteId = $site->id;

    expect(collect($page->getReviewProperty())->pluck('id'))->toHaveCount(2); // unfiltered

    $page->county = '24025';
    expect(collect($page->getReviewProperty())->pluck('id')->all())->toBe([$inTown->id]);
});
