<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\LocationsSetup;
use App\Integrations\Census\County;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\MockCensusGeocoder;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Places\MockPlacesProvider;
use App\Integrations\Places\PlacesProvider;
use App\Locations\CoverageBand;
use App\Locations\CoverageWriter;
use App\Locations\LocalRelevance;
use App\Locations\SiteCoverage;
use App\Locations\TierGate;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operate\TierProgression;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\CoverageFixture;

/**
 * From base A (40.70, -74.50) the fixture towns sit at: Maplewood 2.6 mi, Livingston Twp 11.3 mi,
 * Clinton Twp 21.9 mi, Easton 37.7 mi, Scranton far. `near()` returns them all; the engine's Haversine
 * filter clips to the reach. Essex County (34013) carries the three county-mode subdivisions.
 */
function proxGazetteer(): MockMunicipalityGazetteer
{
    return new MockMunicipalityGazetteer(
        municipalities: CoverageFixture::municipalities(),
        counties: [new County('34013', 'Essex', '34', '013')],
        subdivisions: [
            '34:013' => [
                new Municipality('3401305580', 'Belleville Twp', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15),
                new Municipality('3401351210', 'Montclair Twp', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.21),
            ],
        ],
    );
}

function proxShop(Site $site, int $reach = 15): Location
{
    return Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Shop', 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG,
        'coverage_mode' => Location::MODE_PROXIMITY, 'coverage_radius' => $reach, 'county_geoids' => ['34013'],
    ]);
}

function proxAreas(Site $site)
{
    return CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
}

beforeEach(function () {
    app()->instance(MunicipalityGazetteer::class, proxGazetteer());
});

test('the band chain steps through the rings up to the reach, and closes on an off-step reach', function () {
    $fifteen = new Location(['coverage_mode' => 'proximity', 'coverage_radius' => 15]);
    $twelve = new Location(['coverage_mode' => 'proximity', 'coverage_radius' => 12]);
    $county = new Location(['coverage_mode' => 'county']);

    expect(CoverageBand::chain($fifteen))->toBe(['ring5', 'ring10', 'ring15'])
        ->and(CoverageBand::chain($twelve))->toBe(['ring5', 'ring10', 'ring12'])
        ->and(CoverageBand::chain($county))->toBe(CoverageBand::COUNTY_CHAIN)
        ->and(CoverageBand::chain(null))->toBe(CoverageBand::COUNTY_CHAIN)
        ->and(CoverageBand::ringFor(2.6, ['ring5', 'ring10', 'ring15']))->toBe('ring5')
        ->and(CoverageBand::ringFor(11.3, ['ring5', 'ring10', 'ring15']))->toBe('ring15')
        ->and(CoverageBand::label('ring5', ['ring5', 'ring10']))->toBe('Within 5 mi')
        ->and(CoverageBand::label('ring10', ['ring5', 'ring10']))->toBe('5–10 mi')
        ->and(CoverageBand::label('large'))->toBe('Large');
});

test('a proximity territory seeds only its innermost ring; a county one keeps major + large', function () {
    expect(CoverageBand::autoSelect(new Location(['coverage_mode' => 'proximity', 'coverage_radius' => 15])))->toBe(['ring5'])
        ->and(CoverageBand::autoSelect(new Location(['coverage_mode' => 'county'])))->toBe(['major', 'large']);
});

test('a proximity location is enumerated by distance, a county location by county, and the site unions both', function () {
    $site = Site::factory()->create();
    $shop = proxShop($site, 15);
    $office = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Office', 'lat' => 40.80, 'lng' => -74.20, 'county_geoids' => ['34013'],
    ]);

    $result = app(SiteCoverage::class)->coverage($site);

    $byBase = collect($result->perBase)->keyBy('locationId');
    expect($byBase)->toHaveKeys([$shop->id, $office->id])
        ->and(collect($byBase[$shop->id]->municipalities)->pluck('name')->all())->toBe(['Maplewood', 'Livingston Twp'])   // nearest first, ≤ 15 mi only
        ->and($byBase[$shop->id]->radiusMiles)->toBe(15.0)
        ->and(collect($byBase[$office->id]->municipalities)->pluck('name')->all())->toBe(['Belleville Twp', 'Montclair Twp'])
        ->and($result->unionCount())->toBe(4);
});

test('the writer bands a proximity town by its ring and a county town by its size tier', function () {
    $site = Site::factory()->create();
    $shop = proxShop($site, 15);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Office', 'lat' => 40.80, 'lng' => -74.20, 'county_geoids' => ['34013']]);

    app(CoverageWriter::class)->write($site, app(SiteCoverage::class)->coverage($site));

    $bands = proxAreas($site)->pluck('band', 'name')->all();
    expect($bands['Maplewood'])->toBe('ring5')
        ->and($bands['Livingston Twp'])->toBe('ring15')
        ->and($bands['Belleville Twp'])->toBeNull()   // no ACS population in this fixture → ungrouped, as before
        ->and((float) proxAreas($site)->where('name', 'Maplewood')->value('distance_miles'))->toBe(2.6);

    // A manual town of the shop reads as its innermost ring (distance 0) — a priority page, never gated.
    CoverageArea::create([
        'site_id' => $site->id, 'geo_id' => '3400001', 'name' => 'Hand Added', 'type' => MunicipalityType::Place, 'state' => 'NJ',
        'source' => 'manual', 'source_location_ids' => [$shop->id], 'page_selected' => true,
    ]);
    app(CoverageWriter::class)->write($site, app(SiteCoverage::class)->coverage($site));
    expect(proxAreas($site)->where('name', 'Hand Added')->value('band'))->toBe('ring5');
});

test('a row created without a band takes its size tier — every legacy write path lands a band the gate reads', function () {
    $site = Site::factory()->create();
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'size_tier' => 'large']);

    expect($area->fresh()->band)->toBe('large');
});

test('the seed selects only the 5-mile ring of a proximity market — a big town further out gets no head start', function () {
    $site = Site::factory()->create();
    $shop = proxShop($site, 15);
    $near = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Near Village', 'size_tier' => 'small', 'band' => 'ring5', 'population' => 3000, 'source' => 'county', 'source_location_ids' => [$shop->id]]);
    $bigFar = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Big City', 'size_tier' => 'major', 'band' => 'ring15', 'population' => 90000, 'source' => 'county', 'source_location_ids' => [$shop->id]]);

    expect(app(LocalRelevance::class)->seedInitialSelection($site))->toBe(1)
        ->and($near->fresh()->page_selected)->toBeTrue()
        ->and($bigFar->fresh()->page_selected)->toBeFalse();
});

test('the 10-mile ring unlocks only once the 5-mile ring is indexed, then 15 waits on 10', function () {
    $site = Site::factory()->create(['domain_url' => 'https://shop.example']);
    $shop = proxShop($site, 15);
    $gate = app(TierGate::class);

    foreach (['Alpha' => 'ring5', 'Beta' => 'ring5', 'Gamma' => 'ring10', 'Delta' => 'ring15'] as $name => $band) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'band' => $band, 'size_tier' => 'small', 'source' => 'county', 'source_location_ids' => [$shop->id]]);
    }
    foreach (['Alpha', 'Beta'] as $name) {
        Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'location_id' => null, 'parent_location_id' => $shop->id, 'title' => $name, 'slug' => strtolower($name)]);
    }

    expect($gate->status($site, $shop->id, 'ring5')->buildable)->toBeTrue()
        ->and($gate->status($site, $shop->id, 'ring10')->buildable)->toBeFalse()
        ->and($gate->status($site, $shop->id, 'ring10')->reason)->toContain('Within 5 mi')
        ->and($gate->status($site, $shop->id, 'ring15')->reason)->toContain('Build 5–10 mi first');

    foreach (['alpha', 'beta'] as $slug) {
        $url = 'https://shop.example/'.$slug;
        PageIndexState::create(['site_id' => $site->id, 'url' => $url, 'url_normalized' => UrlNormalizer::url($url), 'index_verdict' => 'PASS']);
    }

    $fresh = app(TierGate::class);
    expect($fresh->status($site, $shop->id, 'ring10')->buildable)->toBeTrue()
        ->and($fresh->status($site, $shop->id, 'ring10')->reason)->toBe('Within 5 mi 100% indexed')
        ->and($fresh->status($site, $shop->id, 'ring15')->buildable)->toBeFalse();

    $market = collect(app(TierProgression::class)->forSite($site, withLinks: false))->firstWhere('id', $shop->id);
    expect(collect($market['tiers'])->pluck('tier')->all())->toBe(['ring5', 'ring10', 'ring15'])
        ->and(collect($market['tiers'])->pluck('label')->all())->toBe(['Within 5 mi', '5–10 mi', '10–15 mi']);
});

describe('the Locations workspace', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
        app()->instance(Geocoder::class, new MockCensusGeocoder(CoverageFixture::A_LAT, CoverageFixture::A_LNG));
        app()->instance(PlacesProvider::class, new MockPlacesProvider);
    });

    it('switches a located base to distance rings, recomputes, and groups the towns nearest-first', function () {
        $site = Site::factory()->create();
        $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Shop', 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);

        $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id);
        expect(proxAreas($site)->where('source', 'county')->count())->toBe(2); // Essex County, by county

        $page->call('setCoverageMode', $loc->id, 'proximity')
            ->assertSee('By distance')
            ->assertSee('Within 5 mi')
            ->assertSee('10–15 mi')
            ->assertDontSee('Counties you serve');

        expect($loc->fresh()->coverage_radius)->toBe(15)
            ->and(proxAreas($site)->where('source', 'county')->pluck('band', 'name')->all())->toBe(['Livingston Twp' => 'ring15', 'Maplewood' => 'ring5']);

        $panel = $page->instance()->panels['panels'][$loc->id];
        expect($panel['chain'])->toBe(['ring5', 'ring10', 'ring15'])
            ->and($panel['proximity'])->toBeTrue()
            ->and(collect($panel['groups']['ring5'])->pluck('name')->all())->toBe(['Maplewood'])
            ->and($panel['tier_locks']['ring10']['locked'])->toBeTrue();

        // Widen to 25: Clinton Twp (21.9 mi) joins in the 15–25 ring.
        $page->call('setCoverageRadius', $loc->id, 25);
        expect(proxAreas($site)->where('name', 'Clinton Twp')->value('band'))->toBe('ring25');

        // Type a reach: the box applies through the same path; a bad value is refused.
        $page->set("radiusInput.{$loc->id}", '10')->call('applyRadius', $loc->id);
        expect($loc->fresh()->coverage_radius)->toBe(10)
            ->and(proxAreas($site)->where('source', 'county')->pluck('name')->all())->toBe(['Maplewood']);
        $page->call('setCoverageRadius', $loc->id, 0)->assertNotified('Reach must be between 1 and 100 miles');
        expect($loc->fresh()->coverage_radius)->toBe(10);

        // …and back to county: the reach is kept but the counties draw the territory again.
        $page->call('setCoverageMode', $loc->id, 'county')->assertSee('Counties you serve');
        expect(proxAreas($site)->where('source', 'county')->pluck('name')->sort()->values()->all())->toBe(['Belleville Twp', 'Montclair Twp']);
    });
});
