<?php

use App\Enums\MunicipalityType;
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
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    // Three NJ counties; countyAt returns the first (Essex = home).
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(
        municipalities: [],
        counties: [
            new County('34013', 'Essex', '34', '013'),
            new County('34003', 'Bergen', '34', '003'),
            new County('34027', 'Morris', '34', '027'),
        ],
        subdivisions: [
            '34:013' => [new Municipality('3401305580', 'Belleville', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15)],
            '34:003' => [new Municipality('3400310000', 'Hackensack', MunicipalityType::CountySubdivision, 'NJ', 40.88, -74.04)],
            '34:027' => [new Municipality('3402710000', 'Morristown', MunicipalityType::CountySubdivision, 'NJ', 40.80, -74.48)],
        ],
    ));
    app()->instance(Geocoder::class, new MockCensusGeocoder(40.80, -74.20));
    app()->instance(PlacesProvider::class, new MockPlacesProvider);
});

function geoidsFor(Site $site, Location $loc): array
{
    return CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('geo_id')->sort()->values()->all();
}

it('accumulates counties across sequential sets — never resets to home', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair',
        'lat' => 40.80, 'lng' => -74.20,
        'home_county_geoid' => '34013', 'county_geoids' => ['34013'],
    ]);

    $page = Livewire::test(LocationsSetup::class)->set('siteId', $site->id);

    // the compact multi-select sends the WHOLE array each change (home + Bergen, then + Morris)
    $page->call('setCounties', $loc->id, ['34013', '34003']);
    expect($loc->refresh()->county_geoids)->toBe(['34013', '34003']);

    $page->call('setCounties', $loc->id, ['34013', '34003', '34027']);
    expect($loc->refresh()->county_geoids)->toBe(['34013', '34003', '34027']);

    // coverage reflects all three counties' towns (one subdivision each)
    expect(geoidsFor($site, $loc))->toBe(['3400310000', '3401305580', '3402710000']);
});

it('removing one county leaves the rest (per-location, independent)', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair',
        'lat' => 40.80, 'lng' => -74.20,
        'home_county_geoid' => '34013', 'county_geoids' => ['34013', '34003', '34027'],
    ]);

    Livewire::test(LocationsSetup::class)
        ->set('siteId', $site->id)
        ->call('setCounties', $loc->id, ['34013', '34027']); // drop Bergen

    expect($loc->refresh()->county_geoids)->toBe(['34013', '34027'])
        ->and(geoidsFor($site, $loc))->toBe(['3401305580', '3402710000']); // Hackensack gone, rest stay
});
