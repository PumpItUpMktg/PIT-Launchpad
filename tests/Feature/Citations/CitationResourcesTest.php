<?php

use App\Enums\UserRole;
use App\Filament\Resources\DirectoryResource\Pages\CreateDirectory;
use App\Filament\Resources\DirectoryResource\Pages\ListDirectories;
use App\Filament\Resources\LocationNapProfileResource\Pages\ListLocationNapProfiles;
use App\Filament\Resources\TenantSharedPhoneResource\Pages\ListTenantSharedPhones;
use App\Models\Directory;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('lists the global directory catalog', function () {
    Directory::factory()->create(['name' => 'Yelp']);

    Livewire::test(ListDirectories::class)->assertOk()->assertSee('Yelp');
});

it('creates a directory from the catalog form', function () {
    Livewire::test(CreateDirectory::class)
        ->fillForm([
            'name' => 'Angi',
            'domain' => 'angi.com',
            'scope' => 'national',
            'authority_tier' => 4,
            'acquisition_type' => 'free',
            'multi_location_policy' => 'one_per_address',
            'seo_value' => 72,
            'business_value' => 40,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Directory::where('name', 'Angi')->exists())->toBeTrue();
});

it('renders the NAP profiles and shared phones resources', function () {
    Livewire::test(ListLocationNapProfiles::class)->assertOk();
    Livewire::test(ListTenantSharedPhones::class)->assertOk();
});
