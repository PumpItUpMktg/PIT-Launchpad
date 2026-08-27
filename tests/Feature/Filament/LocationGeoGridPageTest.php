<?php

use App\Enums\UserRole;
use App\Filament\Pages\LocationGeoGrid;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function seedGridScan(Site $site, Location $location, Keyword $keyword, array $ranks): GeoGridScan
{
    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $location->id, 'keyword_id' => $keyword->id,
        'provider' => 'dataforseo', 'grid_size' => 3, 'spacing_miles' => 1.5,
        'center_lat' => 40.7, 'center_lng' => -74.0, 'zoom' => 13, 'depth_cap' => 20,
        'arp' => 3, 'atrp' => 6, 'solv' => 33.33, 'found_rate' => 88, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    foreach ($ranks as $i => $rank) {
        GeoGridPoint::create([
            'site_id' => $site->id, 'scan_id' => $scan->id, 'row' => intdiv($i, 3), 'col' => $i % 3,
            'lat' => 40.7, 'lng' => -74.0, 'rank' => $rank,
            'competitors' => [['name' => 'Rival Plumbing', 'place_id' => 'ChIJ_rival', 'rank' => 1]],
        ]);
    }

    return $scan;
}

it('is operator-only', function () {
    expect(LocationGeoGrid::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(LocationGeoGrid::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(LocationGeoGrid::canAccess())->toBeTrue();
});

it('renders a card per grid keyword for the selected location', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'Acme Plumbing']);
    $location = Location::factory()->create(['site_id' => $site->id, 'name' => 'Downtown', 'gbp_url' => 'https://maps.google.com/?cid=123', 'place_id' => 'ChIJ_acme', 'lat' => 40.7, 'lng' => -74.0]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency plumber', 'is_grid_keyword' => true]);
    seedGridScan($site, $location, $kw, [1, 2, 3, 4, 5, 6, 7, 8, 9]);

    Livewire::test(LocationGeoGrid::class)
        ->set('siteId', $site->id)
        ->set('locationId', $location->id)
        ->assertOk()
        ->assertSee('emergency plumber')
        ->assertSee('Downtown');
});

it('shows an empty state for a GBP location with no scans', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://maps.google.com/?cid=9', 'place_id' => 'ChIJ_x', 'lat' => 41.0, 'lng' => -73.0]);

    Livewire::test(LocationGeoGrid::class)
        ->set('siteId', $site->id)
        ->set('locationId', $location->id)
        ->assertOk()
        ->assertSee('No geo-grid scans for this location yet');
});

it('aggregates a card\'s competitors for the expanded panel', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $card = ['matrix' => [[
        ['competitors' => [['name' => 'Rival A', 'rank' => 1]]],
        ['competitors' => [['name' => 'Rival A', 'rank' => 2], ['name' => 'Rival B', 'rank' => 5]]],
    ]]];

    $top = (new LocationGeoGrid)->topCompetitors($card);

    expect($top[0])->toMatchArray(['name' => 'Rival A', 'points' => 2, 'best' => 1])
        ->and($top[1])->toMatchArray(['name' => 'Rival B', 'points' => 1, 'best' => 5]);
});
