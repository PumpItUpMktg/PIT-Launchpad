<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\TownCoveragePreview;
use Illuminate\Support\Facades\Bus;

it('computes the town neighbour distribution from the real selector, and --apply repushes every live location page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test', 'brand_name' => 'SPG']);
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hudson office', 'county_geoids' => ['34017']]);

    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturn([new County('34017', 'Hudson County', '34', '017')]);
    $gaz->shouldReceive('countyPolygons')->andReturn([]);
    app()->instance(MunicipalityGazetteer::class, $gaz);

    // Coverage under the parent: three mutually-near towns (~2–3 mi apart, all < 20 mi).
    $cov = fn (string $name, string $geo, float $lat, float $lng) => CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => $geo, 'lat' => $lat, 'lng' => $lng, 'size_tier' => 'large', 'population' => 40000,
        'source_location_ids' => [$parent->id],
    ]);
    $cov('Hoboken', '3401732250', 40.745, -74.030);
    $cov('Jersey City', '3401736000', 40.728, -74.078);
    $cov('Weehawken', '3401778000', 40.770, -74.020);

    // Four LIVE town pages: three anchored (each gets the other two as neighbours) + one with no centroid.
    $liveTown = fn (?string $geo, string $title, string $slug) => Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => $geo, 'title' => $title, 'slug' => $slug,
    ]);
    $liveTown('3401732250', 'Hoboken, NJ', 'hoboken-nj');
    $liveTown('3401736000', 'Jersey City, NJ', 'jersey-city');
    $liveTown('3401778000', 'Weehawken, NJ', 'weehawken-nj');
    $liveTown(null, 'Nowheresville, NJ', 'nowheresville-nj'); // no coverage row / geo → un-anchored drop

    $entry = app(TownCoveragePreview::class)->forSite($site->fresh());

    expect($entry['town'])->toBe(4)
        ->and($entry['hub'])->toBe(0)
        ->and($entry['total'])->toBe(4)
        ->and($entry['dist']['1-2'])->toBe(3)      // each anchored town has its two neighbours
        ->and($entry['dist']['6'])->toBe(0)
        ->and($entry['dist']['drop'])->toBe(1)     // Nowheresville
        ->and($entry['unanchored'])->toBe(1)       // ...because it has no centroid
        ->and($entry['no_range'])->toBe(0);

    // Dry run reports and pushes nothing.
    Bus::fake();
    $this->artisan('launchpad:preview-town-coverage')
        ->assertSuccessful()
        ->expectsOutputToContain('DRY RUN');
    Bus::assertNotDispatched(PublishContent::class);

    // --apply repushes every published location page (idempotent job per page).
    $this->artisan('launchpad:preview-town-coverage --apply')->assertSuccessful();
    Bus::assertDispatchedTimes(PublishContent::class, 4);
});
