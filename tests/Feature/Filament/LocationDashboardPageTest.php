<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\LocationDashboard;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('is operator-only', function () {
    expect(LocationDashboard::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(LocationDashboard::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(LocationDashboard::canAccess())->toBeTrue();
});

it('renders the cluster dashboard for a GBP-backed location', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    $location = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Downtown',
        'gbp_url' => 'https://maps.google.com/?cid=1', 'place_id' => 'p', 'lat' => 40.7, 'lng' => -74.0,
    ]);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'location_id' => $location->id, 'title' => 'Newark, NJ',
    ]);

    Livewire::test(LocationDashboard::class)
        ->set('siteId', $site->id)
        ->set('locationId', $location->id)
        ->assertOk()
        ->assertSee('Cluster performance')
        ->assertSee('Cluster indexing')
        ->assertSee('Keyword movement');
});

it('excludes non-GBP locations from the selector', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'No GBP', 'gbp_url' => null]);

    $locations = Livewire::test(LocationDashboard::class)->set('siteId', $site->id)->instance()->locations;

    expect($locations)->toBe([]);
});
