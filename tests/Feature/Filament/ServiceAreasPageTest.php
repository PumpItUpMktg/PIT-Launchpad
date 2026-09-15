<?php

use App\Enums\UserRole;
use App\Filament\Pages\ServiceAreasPage;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ServiceAreasPage::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(ServiceAreasPage::canAccess())->toBeTrue();
});

it('lists the service areas, opens one to a card per keyword with both maps, and shows the GBP placeholder where no scan exists', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => []]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $kw2 = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'mold remediation', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 1, 'found_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'q', 'rank' => 2, 'collected_at' => now()]);
    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.85, 'lng' => -74.83, 'rank' => 1, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'collected_at' => now()]);

    Livewire::test(ServiceAreasPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Hackettstown office')
        ->assertSee('Warren County, NJ')
        ->assertSee('1 towns')
        ->assertDontSee('Google Business Profile')
        ->call('openArea', $loc->id)
        ->assertSet('locationId', $loc->id)
        ->assertSee('All service areas')
        ->assertSee('sump pump service')
        ->assertSee('mold remediation')
        ->assertSee('Google Business Profile')
        ->assertSee('Area score')
        ->assertSee('No GBP coverage scan for this keyword here yet')          // mold remediation's GBP column
        ->assertSee('No Town Rank scan for this keyword yet')                  // mold remediation's website column
        ->assertSeeHtml('aria-label="GBP map-pack rank by town"')              // sump pump service has both maps
        ->call('closeArea')
        ->assertSet('locationId', null)
        ->assertDontSee('Google Business Profile');
});
