<?php

use App\Enums\UserRole;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('renders the locations list and create form for an operator', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id]);

    Livewire::test(ListLocations::class)->assertOk();
    Livewire::test(CreateLocation::class)->assertOk(); // exercises the hours repeater schema
});

it('creates a location, normalizing the phone to E.164', function () {
    $site = Site::factory()->create();

    Livewire::test(CreateLocation::class)
        ->fillForm([
            'site_id' => $site->id,
            'name' => 'Apex Plumbing — Austin',
            'phone' => '(512) 555-0142',
            'is_storefront' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::where('name', 'Apex Plumbing — Austin')->firstOrFail();
    expect($location->phone)->toBe('+15125550142')
        ->and($location->site_id)->toBe($site->id)
        ->and($location->is_storefront)->toBeTrue();
});

it('renders the edit form with stored per-day hours', function () {
    $site = Site::factory()->create();
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'hours' => ['mon' => ['open' => '09:00', 'close' => '18:00'], 'sun' => 'closed'],
    ]);

    Livewire::test(EditLocation::class, ['record' => $location->id])->assertOk();
});
