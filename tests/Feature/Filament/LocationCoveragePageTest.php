<?php

use App\Enums\UserRole;
use App\Filament\Pages\LocationCoverage;
use App\GeoGrid\GeoGridMetrics;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('is operator-only', function () {
    expect(LocationCoverage::canAccess())->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(LocationCoverage::canAccess())->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(LocationCoverage::canAccess())->toBeTrue();
});

it('renders the coverage progress view for a location with a coverage scan', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    $loc = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair',
        'gbp_url' => 'https://maps.google/?cid=1', 'place_id' => 'p', 'lat' => 40.81, 'lng' => -74.22,
    ]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation']);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Belleville', 'population' => 36000, 'lat' => 40.79, 'lng' => -74.15, 'source_location_ids' => [$loc->id]]);

    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo',
        'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.81, 'center_lng' => -74.22,
        'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => 0, 'coverage_area_id' => $town->id, 'label' => 'Belleville', 'lat' => 40.79, 'lng' => -74.15, 'rank' => 2]);
    app(GeoGridMetrics::class)->recompute($scan);

    Livewire::test(LocationCoverage::class)
        ->set('siteId', $site->id)
        ->set('locationId', $loc->id)
        ->assertOk()
        ->assertSee('Local Visibility Score')
        ->assertSee('sump pump installation')
        ->assertSee('Montclair');
});

it('shows an empty state when the location has no coverage scans', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=9', 'place_id' => 'p', 'lat' => 41.0, 'lng' => -73.0]);

    Livewire::test(LocationCoverage::class)
        ->set('siteId', $site->id)
        ->set('locationId', $loc->id)
        ->assertOk()
        ->assertSee('No coverage scans for this location yet');
});
