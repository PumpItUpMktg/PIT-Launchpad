<?php

use App\Enums\GeoIntent;
use App\Enums\UserRole;
use App\Filament\Pages\GeoCoverageBoard;
use App\Models\CoverageArea;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('renders the GEO coverage board with the matrix + gap list for an operator', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true]);

    $cited = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'intent' => GeoIntent::Hire->value, 'prompt' => 'best repair in union', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $cited->id, 'engine' => 'claude', 'cited' => true, 'checked_at' => now()]);

    $absent = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'intent' => GeoIntent::Cost->value, 'prompt' => 'repair cost in union', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $absent->id, 'engine' => 'claude', 'cited' => false, 'competitors' => ['Rival Plumbing'], 'checked_at' => now()]);

    app(ActiveTenant::class)->set($site->id);

    Livewire::test(GeoCoverageBoard::class)
        ->assertOk()
        ->assertSee('Union')            // town column
        ->assertSee('Repair')           // service row
        ->assertSee('50%')              // the Repair × Union matrix cell (1 of 2 prompts cited)
        ->assertSee("Where you're absent", escape: false)
        ->assertSee('Rival Plumbing');  // the competitor cited on the absent gap
});

it('renders the coverage-accuracy section for brand-anchored coverage prompts', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $town = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true]);

    $cov = GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $town->id, 'size_tier' => 'major', 'kind' => 'coverage', 'prompt' => 'does SPG serve union', 'active' => true]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $cov->id, 'engine' => 'claude', 'cited' => false, 'checked_at' => now()]);   // unaware

    Livewire::test(GeoCoverageBoard::class)
        ->set('siteId', $site->id)
        ->assertSee('Coverage accuracy — does the AI know this shop serves these towns?', escape: false)
        ->assertSee('Unaware — fix listing/schema');
});

it('seeds visibility prompts scoped to the selected shop from the board', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $njShop = Location::factory()->create(['site_id' => $site->id, 'name' => 'NJ Shop']);
    $njTown = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Union', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true, 'source_location_ids' => [$njShop->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'HavreDeGrace', 'state' => 'MD', 'size_tier' => 'major', 'population' => 55000, 'page_selected' => true, 'source_location_ids' => ['md-shop']]);

    Livewire::test(GeoCoverageBoard::class)
        ->set('siteId', $site->id)
        ->set('locationId', $njShop->id)
        ->callAction('seedVisibility');

    // Only the NJ shop's town got prompts — the MD town was not seeded.
    $towns = GeoPrompt::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('coverage_area_id')->filter()->unique()->values()->all();
    expect($towns)->toBe([$njTown->id]);
});

it('offers a brick-and-mortar selector and scopes the grid to the chosen shop', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $svc = Service::factory()->create(['site_id' => $site->id, 'name' => 'Repair']);
    $west = Location::factory()->create(['site_id' => $site->id, 'name' => 'West Shop']);
    $east = Location::factory()->create(['site_id' => $site->id, 'name' => 'East Shop']);

    $townW = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Unionville', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => true, 'source_location_ids' => [$west->id]]);
    $townE = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Edisonville', 'state' => 'NJ', 'size_tier' => 'major', 'population' => 55000, 'page_selected' => true, 'source_location_ids' => [$east->id]]);
    GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $townW->id, 'size_tier' => 'major', 'intent' => 'hire', 'prompt' => 'q west', 'active' => true]);
    GeoPrompt::create(['site_id' => $site->id, 'service_id' => $svc->id, 'coverage_area_id' => $townE->id, 'size_tier' => 'major', 'intent' => 'hire', 'prompt' => 'q east', 'active' => true]);

    Livewire::test(GeoCoverageBoard::class)
        ->set('siteId', $site->id)
        ->assertSee('West Shop')         // both shops offered in the selector
        ->assertSee('East Shop')
        ->assertSee('Unionville')        // all shops → both towns as columns
        ->assertSee('Edisonville')
        ->set('locationId', $west->id)   // focus the West shop
        ->assertSee('Unionville')
        ->assertDontSee('Edisonville');  // East shop's town drops out of the scoped grid
});
