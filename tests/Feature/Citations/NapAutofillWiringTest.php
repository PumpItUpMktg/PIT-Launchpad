<?php

use App\Enums\UserRole;
use App\Filament\Resources\LocationNapProfileResource\Pages\CreateLocationNapProfile;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Integrations\Places\MockPlacesProvider;
use App\Integrations\Places\PlacesProvider;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

/** Google's structured address components for a fully-resolved storefront. */
function wiringComponents(): array
{
    return [
        ['long_name' => '500', 'types' => ['street_number']],
        ['long_name' => 'West 2nd Street', 'types' => ['route']],
        ['long_name' => 'Austin', 'types' => ['locality']],
        ['long_name' => 'Texas', 'short_name' => 'TX', 'types' => ['administrative_area_level_1']],
        ['long_name' => '78701', 'types' => ['postal_code']],
    ];
}

test('creating a GBP-backed location auto-fills its NAP profile', function (): void {
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id); // site_id auto-fills from the lock (no form picker)

    Livewire::test(CreateLocation::class)
        ->fillForm([
            'name' => 'Apex Plumbing — Austin',
            'phone' => '+15125550142',
            'gbp_url' => 'https://maps.google.com/?cid=12345',
            'website' => 'https://apexplumbing.example',
            'place_id' => 'ChIJMOCK00000000000000000000',
            'primary_category' => 'plumber',
            'address_components' => wiringComponents(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::query()->withoutGlobalScope(SiteScope::class)->firstWhere('name', 'Apex Plumbing — Austin');
    $nap = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();

    expect($nap)->not->toBeNull()
        ->and($nap->business_name)->toBe('Apex Plumbing — Austin')
        ->and($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->city)->toBe('Austin')
        ->and($nap->state)->toBe('TX')
        ->and($nap->postal)->toBe('78701')
        ->and($nap->website_url)->toBe('https://apexplumbing.example');
});

test('importing from Google then saving seeds a matching NAP end to end', function (): void {
    app()->bind(PlacesProvider::class, MockPlacesProvider::class);
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id); // site_id auto-fills from the lock (no form picker)

    Livewire::test(CreateLocation::class)
        ->callAction('importFromGoogle', data: [
            'query' => 'Apex Plumbing',
            'place_id' => MockPlacesProvider::PLACE_ID,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::query()->withoutGlobalScope(SiteScope::class)
        ->firstWhere('place_id', MockPlacesProvider::PLACE_ID);
    $nap = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();

    expect($nap)->not->toBeNull()
        ->and($nap->business_name)->toBe('Apex Plumbing — Austin')
        ->and($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->city)->toBe('Austin')
        ->and($nap->state)->toBe('TX')
        ->and($nap->postal)->toBe('78701')
        ->and($nap->phone_primary)->toBe('+15125550142')
        ->and($nap->website_url)->toBe('https://apexplumbing.example');
});

test('creating a non-GBP location does not manufacture a NAP', function (): void {
    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id); // site_id auto-fills from the lock (no form picker)

    Livewire::test(CreateLocation::class)
        ->fillForm(['name' => 'Manual Co'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)->count())->toBe(0);
});

test('editing a GBP-backed location fills blank fields on its existing NAP', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'Apex Plumbing — Austin',
        'phone' => '+15125550142',
        'place_id' => 'ChIJMOCK00000000000000000000',
        'gbp_url' => 'https://maps.google.com/?cid=12345',
        'address_components' => wiringComponents(),
    ]);
    LocationNapProfile::factory()->create([
        'site_id' => $site->id, 'location_id' => $location->id,
        'business_name' => 'Kept Name', 'address_1' => '', 'postal' => '',
    ]);

    Livewire::test(EditLocation::class, ['record' => $location->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $nap = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
        ->where('location_id', $location->id)->first();

    expect($nap->business_name)->toBe('Kept Name')   // authoritative value untouched
        ->and($nap->address_1)->toBe('500 West 2nd Street')
        ->and($nap->postal)->toBe('78701');
});

test('the Autofill from GBP action fills the NAP create form', function (): void {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'Apex Plumbing — Austin',
        'phone' => '+15125550142',
        'place_id' => 'ChIJMOCK00000000000000000000',
        'gbp_url' => 'https://maps.google.com/?cid=12345',
        'address_components' => wiringComponents(),
    ]);

    Livewire::test(CreateLocationNapProfile::class)
        ->fillForm(['location_id' => $location->id])
        ->callAction('autofillFromGbp')
        ->assertHasNoActionErrors()
        ->assertFormSet([
            'business_name' => 'Apex Plumbing — Austin',
            'address_1' => '500 West 2nd Street',
            'city' => 'Austin',
            'state' => 'TX',
            'postal' => '78701',
        ]);
});
