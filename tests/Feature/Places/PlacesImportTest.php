<?php

use App\Enums\UserRole;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Integrations\Places\MockPlacesProvider;
use App\Integrations\Places\PlacesProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('the places smoke-test command reports OK with a working provider', function () {
    app()->bind(PlacesProvider::class, MockPlacesProvider::class);

    $this->artisan('launchpad:places-smoke-test')
        ->expectsOutputToContain('Places API reachable')
        ->assertSuccessful();
});

test('the places smoke-test command fails clearly with no API key', function () {
    // Default real client + empty key in the test env → a clear operator error.
    $this->artisan('launchpad:places-smoke-test')
        ->expectsOutputToContain('GOOGLE_MAPS_API_KEY is not set')
        ->assertFailed();
});

it('the Import from Google action autofills the create form (never silent-saves)', function () {
    app()->bind(PlacesProvider::class, MockPlacesProvider::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Livewire::test(CreateLocation::class)
        ->callAction('importFromGoogle', data: [
            'query' => 'Apex Plumbing',
            'place_id' => MockPlacesProvider::PLACE_ID,
        ])
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'name' => 'Apex Plumbing — Austin',
            'phone' => '+15125550142',
            'gbp_url' => 'https://maps.google.com/?cid=12345678901234567890',
            'place_id' => MockPlacesProvider::PLACE_ID,
        ]);
});
